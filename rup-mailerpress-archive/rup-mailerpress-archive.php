<?php
/**
 * Plugin Name: RUP MailerPress Campaign Archive
 * Description: Bricks-friendly newsletter archive with saved MailerPress audiences, streams, and subscribe links.
 * Version: 1.2.3
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }

final class MP_Bricks_Campaign_Archive {
    const CPT = 'fc_campaign_archive';
    const TAX = 'fc_campaign_stream';
    const OPTION = 'mpbca_campaign_settings';
    const META_ID = '_mpbca_campaign_id';

    public function boot() {
        add_action('init', [$this, 'register']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_mpbca_save', [$this, 'save']);
        add_action('mailerpress_campaign_sent', [$this, 'on_sent'], 10, 2);
        add_action('created_' . self::TAX, [$this, 'save_stream_fields']);
        add_action('edited_' . self::TAX, [$this, 'save_stream_fields']);
        add_action(self::TAX . '_add_form_fields', [$this, 'add_stream_fields']);
        add_action(self::TAX . '_edit_form_fields', [$this, 'edit_stream_fields']);
        add_filter('manage_' . self::CPT . '_posts_columns', [$this, 'columns']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'column'], 10, 2);
        add_shortcode('mp_campaign_stream_info', [$this, 'shortcode_info']);
        add_shortcode('fc_campaign_stream_info', [$this, 'shortcode_info']);
        add_shortcode('mp_campaign_subscribe', [$this, 'shortcode_subscribe']);
        add_shortcode('mp_campaign_email', [$this, 'shortcode_email']);
    }
    public function register() {
        register_post_type(self::CPT, [
            'labels' => ['name'=>'Campaign Archive','singular_name'=>'Archived MailerPress Campaign'],
            'public'=>true, 'show_in_rest'=>true, 'has_archive'=> 'newsletters',
            'rewrite'=>['slug'=>'campaign-archive','with_front'=>false],
            'menu_icon'=>'dashicons-email-alt2',
            'supports'=>['title','editor','excerpt','custom-fields','thumbnail'],
        ]);
        register_taxonomy(self::TAX, self::CPT, [
            'labels'=>['name'=>'Campaign Streams','singular_name'=>'Campaign Stream'],
            'public'=>true,'show_in_rest'=>true,'hierarchical'=>false,
            'rewrite'=>['slug'=>'campaign-stream'],
        ]);
        // Streams are entirely user-managed in WordPress.
        // Do not auto-create deleted terms on each request.
        foreach (['_mpbca_campaign_id','_mpbca_campaign_status','_mpbca_synced_at','_mpbca_targeting','_mpbca_audience_tags','_mpbca_audience_lists','_mpbca_audience_segments','_mpbca_email_url'] as $key) {
            register_post_meta(self::CPT, $key, ['single'=>true, 'show_in_rest'=>false, 'type'=>'string', 'auth_callback'=>function(){return current_user_can('edit_posts');}]);
        }
    }
    private function table() { return $GLOBALS['wpdb']->prefix . 'mailerpress_campaigns'; }
    private function table_exists() {
        global $wpdb;
        $table = $this->table();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }
    private function campaign($id) {
        global $wpdb;
        if (!$this->table_exists()) { return null; }
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . esc_sql($this->table()) . '` WHERE campaign_id=%d', absint($id)), ARRAY_A);
    }
    private function campaigns() {
        global $wpdb;
        if (!$this->table_exists()) { return []; }
        return $wpdb->get_results('SELECT campaign_id,name,subject,status,created_at,updated_at FROM `' . esc_sql($this->table()) . '` ORDER BY campaign_id DESC LIMIT 200', ARRAY_A) ?: [];
    }
    private function options() { $v=get_option(self::OPTION, []); return is_array($v) ? $v : []; }
    private function config($campaign) {
        $v = maybe_unserialize($campaign['config'] ?? '');
        if (is_string($v)) { $v=json_decode($v, true); }
        return is_array($v) ? $v : [];
    }
    private function items($value) {
        if (is_string($value)) { $decoded=json_decode($value,true); $value=is_array($decoded)?$decoded:[]; }
        return is_array($value)?$value:[];
    }
    private function ids($items, $field) {
        $ids=[];
        foreach ($this->items($items) as $item) {
            $id = is_array($item) ? ($item[$field] ?? $item['id'] ?? null) : (is_object($item) ? ($item->$field ?? $item->id ?? null) : $item);
            if (is_numeric($id) && (int)$id > 0) { $ids[]=(int)$id; }
        }
        return array_values(array_unique($ids));
    }
    private function lookup_names($kind, $ids) {
        global $wpdb;
        if (!$ids) { return []; }
        $table=$wpdb->prefix . ($kind==='tag'?'mailerpress_tags':'mailerpress_lists');
        $col= $kind==='tag'?'tag_id':'list_id';
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));
        $sql=$wpdb->prepare('SELECT name FROM `' . esc_sql($table) . '` WHERE ' . $col . ' IN (' . $placeholders . ') ORDER BY name', $ids);
        return array_map('strval', $wpdb->get_col($sql) ?: []);
    }
    private function audience($campaign) {
        $config=$this->config($campaign);
        $schedule=isset($config['automatedCampaignSchedule']) && is_array($config['automatedCampaignSchedule']) ? $config['automatedCampaignSchedule'] : [];
        $tags=$this->ids($config['tags'] ?? $schedule['tags'] ?? [], 'tag_id');
        $lists=$this->ids($config['lists'] ?? $schedule['lists'] ?? [], 'list_id');
        $segments=$this->items($config['segment'] ?? $schedule['segment'] ?? []);
        return [
            'targeting'=>sanitize_key((string)($config['recipientTargeting'] ?? $schedule['recipientTargeting'] ?? 'classic')),
            'tag_ids'=>$tags,'list_ids'=>$lists,'tags'=>$this->lookup_names('tag',$tags),
            'lists'=>$this->lookup_names('list',$lists),'segment'=>$segments,
        ];
    }
    /**
     * Convert MailerPress compiled email HTML into content safe for the Bricks
     * Post Content element. MJML compilation produces a full HTML document:
     * stripping tags from that whole document leaves CSS text visible on page.
     * Keep the email's BODY and its inline styles, not its HEAD stylesheets.
     */
    private function web_content($html) {
        $html = (string) $html;
        if (trim($html) === '') { return ''; }
        // Remove document head (including CSS, Outlook conditionals, metadata).
        $html = preg_replace('~<head\b[^>]*>.*?</head\s*>~is', '', $html);
        if (preg_match('~<body\b[^>]*>(.*?)</body\s*>~is', $html, $match)) {
            $html = $match[1];
        }
        // No email document-level styles or client-specific rendering scripts.
        $html = preg_replace('~<(style|script|title|meta|link)\b[^>]*>(.*?)</\1\s*>~is', '', $html);
        $html = preg_replace('~<(meta|link)\b[^>]*\/?\s*>~is', '', $html);
        $html = preg_replace('~<!--[\s\S]*?-->~', '', $html);
        // Email-recipient-specific links must not be exposed in a public archive.
        $html = preg_replace('~<a\b[^>]*href=[\"\'][^\"\']*(?:UNSUB_LINK|MANAGE_SUB_LINK|action=(?:unsubscribe|manage))[^\"\']*[\"\'][^>]*>.*?</a>~is', '', $html);
        $html = preg_replace('~<span\b[^>]*class=[\"\'][^\"\']*merge-tag-span[^\"\']*[\"\'][^>]*>.*?</span>~is', '', $html);
        $html = str_replace(['{{UNSUB_LINK}}', '{{MANAGE_SUB_LINK}}', '%UNSUB_LINK%', '%MANAGE_SUB_LINK%'], '', $html);
        $html = preg_replace('~<p\b[^>]*>\s*This email was sent (?:to you )?because you opted in.*?</p>~is', '', $html);
        // MJML constrains the header logo through email table wrappers. After
        // extracting the body, Bricks may allow the bare image to fill the
        // available width. Apply an explicit width to OUR known header logo
        // only; do not resize illustrations or other newsletter imagery.
        $html = preg_replace_callback('~<img\b[^>]*>~i', function ($matches) {
            $img = $matches[0];
            if (!preg_match('~\bsrc\s*=\s*([\"\'])(.*?)\1~i', $img, $src)) {
                return $img;
            }
            $path = wp_parse_url(html_entity_decode($src[2], ENT_QUOTES, 'UTF-8'), PHP_URL_PATH);
            if (!is_string($path) || !preg_match('~/(?:Really-useful-Plugins-DARK)\.png$~i', $path)) {
                return $img;
            }
            $img = preg_replace('~\swidth\s*=\s*(?:[\"\'][^\"\']*[\"\']|[^\s>]+)~i', '', $img);
            if (preg_match('~\sstyle\s*=\s*([\"\'])(.*?)\1~is', $img, $style)) {
                $updated = rtrim($style[2], '; ') . '; width:190px; max-width:100%; height:auto;';
                $img = str_replace($style[0], ' style="' . esc_attr($updated) . '"', $img);
            } else {
                $img = preg_replace('~\s*/?>$~', ' style="width:190px;max-width:100%;height:auto;" />', $img);
            }
            return preg_replace('~\s*/?>$~', ' width="190" />', $img);
        }, $html);
        // Let WordPress sanitize tags and attributes, retaining safe inline styles.
        return trim(wp_kses_post($html));
    }

    public function on_sent($id, $data=[]) {
        $opts=$this->options();
        if (!empty($opts[(int)$id]['enabled'])) { $this->sync((int)$id); }
    }
    private function sync($id) {
        $campaign=$this->campaign($id);
        if (!$campaign || $campaign['status']!=='sent') { return new WP_Error('not_sent','Only sent campaigns can be published.'); }
        $opts=$this->options();
        $setting=$opts[$id]??[];
        if (empty($setting['enabled'])) { return new WP_Error('disabled','Campaign not selected.'); }
        $stream=sanitize_title($setting['stream']??'');
        if (!$stream || !term_exists($stream,self::TAX)) { return new WP_Error('stream','Choose an existing campaign stream.'); }
        $existing=get_posts(['post_type'=>self::CPT,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids',
            'meta_query'=>[['key'=>self::META_ID,'value'=>(string)$id]]]);
        // Reuse MailerHive-linked issue rather than creating a duplicate when possible.
        if (!$existing) {
            $existing=get_posts(['post_type'=>self::CPT,'post_status'=>'any','posts_per_page'=>1,'fields'=>'ids',
                'meta_query'=>[['key'=>'_mh_campaign_id','value'=>(string)$id]]]);
        }
        $subject=trim((string)($campaign['subject']??''));
        $name=trim((string)($campaign['name']??''));
        $title=wp_strip_all_tags($subject?:($name?:'Campaign '.$id));
        $body=(string)($campaign['content_html']??'');
        // The final compiled snapshot is preferred over editor/source content.
        $snapshot=get_option('mailerpress_batch_'.$id.'_html','');
        $source=is_string($snapshot) && trim($snapshot)!=='' ? $snapshot : $body;
        $web_body=$this->web_content($source);
        $excerpt=wp_trim_words(wp_strip_all_tags($web_body?:$body),35);
        $post=['post_type'=>self::CPT,'post_status'=>'publish','post_title'=>$title,
            'post_excerpt'=>$excerpt];
        if (!$existing && !empty($campaign['updated_at'])) {
            $ts=strtotime((string)$campaign['updated_at']);
            if ($ts) $post['post_date']=wp_date('Y-m-d H:i:s',$ts);
        }
        // Re-sync must repair archive content; otherwise old broken CSS survives.
        // Never wipe a post if the campaign has no readable HTML available.
        if ($web_body!=='') {
            $post['post_content']=$web_body;
            if ($existing) {
                $previous=get_post_field('post_content',(int)$existing[0]);
                if ($previous!==$web_body && !metadata_exists('post',(int)$existing[0],'_mpbca_pre_repair_content')) {
                    update_post_meta((int)$existing[0],'_mpbca_pre_repair_content',$previous);
                }
            }
        }
        if ($existing) { $post['ID']=(int)$existing[0]; }
        $post_id=$existing ? wp_update_post(wp_slash($post),true) : wp_insert_post(wp_slash($post),true);
        if (is_wp_error($post_id)) { return $post_id; }
        wp_set_object_terms($post_id,[$stream],self::TAX,false);
        update_post_meta($post_id,'mh_archive_stream',$stream);
        update_post_meta($post_id,'mh_archive_stream_name',(string)(get_term_by('slug',$stream,self::TAX)->name ?? $stream));
        $stream_term=get_term_by('slug',$stream,self::TAX);
        if ($stream_term) update_post_meta($post_id,'mh_signup_tag_id',(int)get_term_meta($stream_term->term_id,'_rup_signup_tag_id',true));
        $aud=$this->audience($campaign);
        update_post_meta($post_id,self::META_ID,$id);
        update_post_meta($post_id,'_mpbca_campaign_status','sent');
        update_post_meta($post_id,'_mpbca_synced_at',current_time('mysql'));
        update_post_meta($post_id,'_mpbca_targeting',$aud['targeting']);
        foreach (['tags','lists'] as $key) { update_post_meta($post_id,'_mpbca_audience_'.$key,implode(', ',$aud[$key])); }
        update_post_meta($post_id,'_mpbca_audience_segments',wp_json_encode($aud['segment']));
        update_post_meta($post_id,'_mpbca_audience', $aud);
        $url=home_url('/mp-email/'.$id.'-'.sanitize_title($name?:'campaign').'/');
        update_post_meta($post_id,'_mpbca_email_url', $url);
        update_post_meta($post_id,'_mpbca_has_snapshot',!empty($snapshot)?'yes':'no');
        return $post_id;
    }
    public function menu() {
        add_submenu_page('tools.php','MailerPress Campaign Archive','Campaign Archive','manage_options','mpbca',[$this,'admin']);
    }
    public function admin() {
        if (!current_user_can('manage_options')) { return; }
        $opts=$this->options(); $campaigns=$this->campaigns(); $streams=get_terms(['taxonomy'=>self::TAX,'hide_empty'=>false]);
        echo '<div class="wrap"><h1>MailerPress Campaign Archive</h1><p>Select sent campaigns to publish. Assign an archive stream, then edit each stream to set its public subscribe URL. Sent campaign audience details are saved automatically.</p>';
        if (isset($_GET['mpbca_updated'])) { echo '<div class="notice notice-success"><p>Settings saved; selected campaigns synced.</p></div>'; }
        if (!$this->table_exists()) { echo '<div class="notice notice-error"><p>MailerPress campaign table not found.</p></div></div>'; return; }
        echo '<p><a href="'.esc_url(admin_url('edit-tags.php?taxonomy='.self::TAX.'&post_type='.self::CPT)).'">Manage streams and subscribe links</a></p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mpbca_save">';
        wp_nonce_field('mpbca_save');
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Campaign</th><th>Status</th><th>Archive?</th><th>Stream</th><th>Sync now</th><th>Audience</th></tr></thead><tbody>';
        foreach ($campaigns as $c) {
            $id=(int)$c['campaign_id'];$o=$opts[$id]??[];$aud=$this->audience($this->campaign($id));
            echo '<tr><td>'.esc_html($id).'</td><td>'.esc_html($c['name'].' / '.$c['subject']).'</td><td>'.esc_html($c['status']).'</td>';
            echo '<td><input type="checkbox" name="campaigns['.esc_attr($id).'][enabled]" value="1" '.checked(!empty($o['enabled']),true,false).'></td>';
            echo '<td><select name="campaigns['.esc_attr($id).'][stream]"><option value="">— Select —</option>';
            foreach ($streams as $s) { echo '<option value="'.esc_attr($s->slug).'" '.selected($o['stream']??'',$s->slug,false).'>'.esc_html($s->name).'</option>'; }
            echo '</select></td><td><input type="checkbox" name="campaigns['.esc_attr($id).'][sync]" value="1" '.disabled($c['status']!=='sent',true,false).'></td>';
            echo '<td><small>Tags: '.esc_html(implode(', ',$aud['tags'])).'<br>Lists: '.esc_html(implode(', ',$aud['lists'])).'<br>Targeting: '.esc_html($aud['targeting']).'</small></td></tr>';
        }
        echo '</tbody></table><p><button class="button button-primary">Save and sync selected</button></p></form></div>';
    }
    public function save() {
        if (!current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('mpbca_save');
        $input=isset($_POST['campaigns'])&&is_array($_POST['campaigns'])?wp_unslash($_POST['campaigns']):[];
        $opts=$this->options();
        foreach ($this->campaigns() as $c) {
            $id=(int)$c['campaign_id'];$v=isset($input[$id])&&is_array($input[$id])?$input[$id]:[];
            $opts[$id]=['enabled'=>!empty($v['enabled'])?1:0,'stream'=>sanitize_title($v['stream']??'')];
        }
        update_option(self::OPTION,$opts,false);
        foreach ($input as $id=>$v) { if (!empty($v['sync']) && !empty($opts[(int)$id]['enabled'])) { $this->sync(absint($id)); } }
        wp_safe_redirect(add_query_arg('mpbca_updated','1',admin_url('tools.php?page=mpbca')));exit;
    }
    /**
     * Read actual MailerPress tag records for the WordPress stream editor.
     * Existing saved IDs are retained even if MailerPress is temporarily unavailable.
     */
    private function render_tag_picker($selected, $edit) {
        global $wpdb;
        $table = $wpdb->prefix . 'mailerpress_tags';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
        $tags = $exists ? $wpdb->get_results('SELECT tag_id, name FROM `' . esc_sql($table) . '` ORDER BY name ASC') : [];
        $control = '<select id="rup_signup_tag_id" name="rup_signup_tag_id"><option value="0">— No signup tag —</option>';
        $found = !$selected;
        foreach ($tags as $tag) {
            if ((int) $tag->tag_id === (int) $selected) { $found = true; }
            $control .= '<option value="' . esc_attr($tag->tag_id) . '" ' . selected($selected, $tag->tag_id, false) . '>' . esc_html($tag->name) . ' (ID ' . absint($tag->tag_id) . ')</option>';
        }
        if (!$found) {
            $control .= '<option selected value="' . absint($selected) . '">Previously saved tag ID ' . absint($selected) . ' (not found)</option>';
        }
        $control .= '</select><p class="description">Choose the MailerPress tag to assign when someone subscribes to this stream. This does not subscribe anyone by itself.</p>';
        if (!$exists) { $control .= '<p class="description">MailerPress tags table not found; existing selection is preserved.</p>'; }
        if ($edit) {
            echo '<tr class="form-field"><th><label for="rup_signup_tag_id">MailerPress signup tag</label></th><td>' . $control . '</td></tr>';
        } else {
            echo '<div class="form-field"><label for="rup_signup_tag_id">MailerPress signup tag</label>' . $control . '</div>';
        }
    }
    public function add_stream_fields() {
        $this->render_tag_picker(0, false);
        echo '<div class="form-field"><label for="mpbca_subscribe_url">Subscribe URL</label><input name="mpbca_subscribe_url" id="mpbca_subscribe_url" type="url" placeholder="https://example.com/subscribe/"><p>Link to a public MailerPress form/page that subscribes visitors to this stream’s list or tag. Creating this URL does not assign contacts automatically.</p></div>';
        wp_nonce_field('mpbca_stream','mpbca_stream_nonce');
    }
    public function edit_stream_fields($term) {
        $this->render_tag_picker((int) get_term_meta($term->term_id, '_rup_signup_tag_id', true), true);
        echo '<tr class="form-field"><th><label for="mpbca_subscribe_url">Subscribe URL</label></th><td><input name="mpbca_subscribe_url" id="mpbca_subscribe_url" type="url" value="'.esc_attr(get_term_meta($term->term_id,'_mpbca_subscribe_url',true)).'" class="regular-text"><p class="description">Public signup page/form configured to add subscribers to the correct MailerPress tag or list.</p></td></tr>';
        wp_nonce_field('mpbca_stream','mpbca_stream_nonce');
    }
    public function save_stream_fields($term_id) {
        if (!current_user_can('manage_categories') || !isset($_POST['mpbca_stream_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mpbca_stream_nonce'])),'mpbca_stream')) { return; }
        $url=isset($_POST['mpbca_subscribe_url'])?esc_url_raw(wp_unslash($_POST['mpbca_subscribe_url'])):'';
        update_term_meta($term_id,'_mpbca_subscribe_url',$url);
        update_term_meta($term_id,'_rup_signup_tag_id',absint($_POST['rup_signup_tag_id']??0));
    }
    public function columns($cols) { $cols['mpbca_stream']='Stream';$cols['mpbca_audience']='Sent to';$cols['mpbca_sync']='Synced';return $cols; }
    public function column($col,$post_id) {
        if ($col==='mpbca_stream') { $terms=get_the_terms($post_id,self::TAX);echo $terms&&!is_wp_error($terms)?esc_html(implode(', ',wp_list_pluck($terms,'name'))):'—'; }
        if ($col==='mpbca_audience') { echo esc_html(get_post_meta($post_id,'_mpbca_audience_tags',true).' | '.get_post_meta($post_id,'_mpbca_audience_lists',true)); }
        if ($col==='mpbca_sync') { echo esc_html(get_post_meta($post_id,'_mpbca_synced_at',true)); }
    }
    public function info($post_id=0) {
        $post_id=absint($post_id)?:get_the_ID();
        if (!$post_id || get_post_type($post_id)!==self::CPT) { return []; }
        $terms=get_the_terms($post_id,self::TAX);
        $term=$terms&&!is_wp_error($terms)?reset($terms):null;
        $aud=get_post_meta($post_id,'_mpbca_audience',true);
        if (!is_array($aud)) { $aud=[]; }
        return [
            'stream_name'=>$term?$term->name:'', 'stream_slug'=>$term?$term->slug:'',
            'stream_url'=>$term && !is_wp_error(get_term_link($term))?get_term_link($term):'',
            'subscribe_url'=>$term?(string)get_term_meta($term->term_id,'_mpbca_subscribe_url',true):'',
            'signup_tag_id'=>$term?(int)get_term_meta($term->term_id,'_rup_signup_tag_id',true):0,
            'tags'=>implode(', ',$aud['tags']??[]), 'lists'=>implode(', ',$aud['lists']??[]),
            'tag_ids'=>$aud['tag_ids']??[],'list_ids'=>$aud['list_ids']??[],
            'targeting'=>$aud['targeting']??'', 'segments'=>$aud['segment']??[],
            'campaign_id'=>(int)get_post_meta($post_id,self::META_ID,true),
            'email_url'=>(string)get_post_meta($post_id,'_mpbca_email_url',true),
        ];
    }
    public function shortcode_info($atts) {
        $a=shortcode_atts(['field'=>'stream_name','post_id'=>0],$atts);
        $info=$this->info(absint($a['post_id']));$field=sanitize_key($a['field']);
        if (!isset($info[$field]) || !is_scalar($info[$field])) { return ''; }
        return in_array($field,['stream_url','subscribe_url','email_url'],true)?esc_url($info[$field]):esc_html((string)$info[$field]);
    }
    public function shortcode_subscribe($atts) {
        $a=shortcode_atts(['post_id'=>0,'label'=>'Subscribe to this newsletter','class'=>'mpbca-subscribe-button'],$atts);
        $info=$this->info(absint($a['post_id']));
        if (empty($info['subscribe_url'])) { return ''; }
        return '<a class="'.esc_attr($a['class']).'" href="'.esc_url($info['subscribe_url']).'">'.esc_html($a['label']).'</a>';
    }
    public function shortcode_email($atts) {
        $a=shortcode_atts(['post_id'=>0,'height'=>'1100','title'=>'Newsletter'], $atts);
        $info=$this->info(absint($a['post_id']));
        if (empty($info['email_url'])) { return ''; }
        $height=max(200,min(5000,absint($a['height'])));
        return '<iframe loading="lazy" title="'.esc_attr($a['title']).'" src="'.esc_url($info['email_url']).'" style="width:100%;height:'.esc_attr($height).'px;border:0" sandbox="allow-same-origin allow-popups" referrerpolicy="no-referrer"></iframe>';
    }
}
add_action('plugins_loaded',function(){ (new MP_Bricks_Campaign_Archive())->boot(); });
/**
 * Bricks PHP dynamic helper. Enable it in Bricks' allowed PHP functions if needed.
 * Returns an array with stream_name, stream_slug, tags, lists, subscribe_url, etc.
 */
function mp_campaign_archive_get_stream_tag_info($post_id=0) {
    // Lightweight lookup that also works before plugins_loaded has created an instance.
    $id=absint($post_id)?:get_the_ID();
    if (!$id || get_post_type($id)!==MP_Bricks_Campaign_Archive::CPT) { return []; }
    $terms=get_the_terms($id,MP_Bricks_Campaign_Archive::TAX);
    $term=$terms&&!is_wp_error($terms)?reset($terms):null;
    $aud=get_post_meta($id,'_mpbca_audience',true);
    $aud=is_array($aud)?$aud:[];
    return [
        'name'=>$term?$term->name:'', 'slug'=>$term?$term->slug:'',
        'stream_name'=>$term?$term->name:'','stream_slug'=>$term?$term->slug:'',
        'tags'=>$aud['tags']??[],'tag_ids'=>$aud['tag_ids']??[],
        'lists'=>$aud['lists']??[],'list_ids'=>$aud['list_ids']??[],
        'targeting'=>$aud['targeting']??'', 'segments'=>$aud['segment']??[],
        'subscribe_url'=>$term?(string)get_term_meta($term->term_id,'_mpbca_subscribe_url',true):'',
            'signup_tag_id'=>$term?(int)get_term_meta($term->term_id,'_rup_signup_tag_id',true):0,
        'campaign_id'=>(int)get_post_meta($id,'_mpbca_campaign_id',true),
    ];
}

/** Legacy-compatible helper used by Bricks or custom PHP. */
function fc_campaign_archive_get_stream_tag_info($post_id=0) {
    $info=mp_campaign_archive_get_stream_tag_info($post_id);
    if (empty($info)) return [];
    $info['signup_tag_id']=0;
    $id=absint($post_id)?:get_the_ID();
    $terms=get_the_terms($id,'fc_campaign_stream');
    if ($terms && !is_wp_error($terms)) {
        $term=reset($terms);
        $info['signup_tag_id']=(int)get_term_meta($term->term_id,'_rup_signup_tag_id',true);
    }
    return $info;
}

/**
 * FlowMattic-friendly lookup: resolve an archive stream name or slug to a
 * MailerPress *signup* tag, without requiring a post ID or archive context.
 *
 * Example: rup_get_mailerpress_tag_from_stream('General');
 * Returns an associative array suitable for JSON / a PHP function step.
 * The stored ID comes from Campaign Streams > Edit > MailerPress signup tag.
 * No contact is created or updated by this function.
 */
function rup_get_mailerpress_tag_from_stream($stream) {
    // Some automation tools wrap the field in an input array.
    if (is_array($stream)) {
        $stream = $stream['stream'] ?? '';
    }
    if (!is_scalar($stream)) {
        return ['success' => false, 'error' => 'Invalid stream input'];
    }
    $stream = trim((string) $stream);
    if ($stream === '') {
        return ['success' => false, 'error' => 'No stream supplied'];
    }
    $taxonomy = MP_Bricks_Campaign_Archive::TAX;
    $term = get_term_by('slug', sanitize_title($stream), $taxonomy);
    if (!$term) {
        $term = get_term_by('name', $stream, $taxonomy);
    }
    if (!$term || is_wp_error($term)) {
        return ['success' => false, 'error' => 'Stream not found', 'input' => $stream];
    }
    $tag_id = (int) get_term_meta($term->term_id, '_rup_signup_tag_id', true);
    if (!$tag_id) {
        return ['success' => false, 'error' => 'No MailerPress tag mapped', 'stream' => $term->slug, 'stream_name' => $term->name];
    }
    global $wpdb;
    $table = $wpdb->prefix . 'mailerpress_tags';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    if (!$exists) {
        return ['success' => false, 'error' => 'MailerPress tags table unavailable', 'stream' => $term->slug];
    }
    $tag_name = $wpdb->get_var($wpdb->prepare('SELECT name FROM `' . esc_sql($table) . '` WHERE tag_id = %d', $tag_id));
    if ($tag_name === null) {
        return ['success' => false, 'error' => 'Mapped MailerPress tag no longer exists', 'stream' => $term->slug, 'mailerpress_tag_id' => $tag_id];
    }
    return [
        'success' => true,
        'stream' => $term->slug,
        'stream_name' => $term->name,
        'mailerpress_tag_id' => $tag_id,
        'mailerpress_tag_name' => (string) $tag_name,
    ];
}
