<?php
/*
Testing
Plugin Name: yolink Search for WordPress
Plugin URI: http://yolink.com/worpress
Description: Drop-in replacement for WordPress search that actually provides relevant results. Cloud-based service allows for fast indexing and searching, even on large sites. Users can share directly from search results. 
Version: 1.0.5
Author: WP Engine
Author URI: http://wpengine.com
*/

$yolink = new YolinkSearch;

class YolinkSearch {
	
    var $api_key;
    var $never_include_post_types;
    var $allowed_post_types;
    var $throttle_rate;
    var $social_services;
    var $http_requests;
    var $using_full_content;
    var $results_title;
    var $search_post_ids;
    var $max_results;
    var $use_mini_share;
    var $ignore_robots;

	function __construct() 
	{	
		load_plugin_textdomain( 'yolink' );
		$yolink_config = get_option('yolink_config');
		if( isset( $yolink_config['yolink_apikey']) )
			$this->api_key = $yolink_config['yolink_apikey'];
		else
			$this->api_key = null;
		if( isset( $yolink_config['allowed_post_types'] ) )
			$this->allowed_post_types = $yolink_config['allowed_post_types'];
		else
			$this->allowed_post_types = array('post','page');
						
		$this->never_include_post_types = array( 'revision', 'attachment', 'nav_menu_item' );
		$this->throttle_rate = 5;
        $this->ignore_robots = 'true';
        $this->social_services = array( 'share' => false, 'googledocs' => false, 'fblike' => '', 'tweet' => '' );
		if( defined('YOLINK_MAX_RESULTS') )
			$this->max_results = YOLINK_MAX_RESULTS;
		else
			$this->max_results = 2;
		
		if( defined( 'YOLINK_USE_MAXI_SHARE' ) )
			$this->use_mini_share = 'standard';
		else
			$this->use_mini_share = 'mini';
			
		$this->http_requests = array();
		$this->results_title = '';
		
		$this->run_hooks();
	}
	function __destruct() {
	}

	function run_hooks()
	{	
		add_action( 'admin_init', array( $this, 'save_registration' ) );
		add_action( 'admin_init', array( $this, 'save_options' ) );
		add_action( 'admin_init', array( $this, 'crawl_submit' ) );
		add_action( 'admin_init', array( $this, 'save_services_settings' ) );
		
		add_action( 'admin_notices', array( $this, 'crawl_notices'), 50 );
		
        add_action( 'admin_menu', array( $this, 'add_menu' ) );

        # -- 1.0.5 Only add the hooks outside of internal wp-admin search. Currently it is conflicting
        # with internal ones, so this will ensure that search hooks are applied for external searches only
        if( !is_admin() )
        {       
            add_filter( 'get_search_form', array( $this, 'get_search_form' ), 50 );
            add_filter( 'pre_get_posts', array( $this, '_yolink_query_where' ), 10, 1 );
            add_filter( 'posts_where', array( $this, 'yolink_sql_where' ),20 );
            add_filter( 'posts_orderby', array( $this, '_yolink_query_order' ),21 );		
                
            add_action( 'pre_transient_yolink_search_results_' . $_GET['s'],array( $this, 'do_search' ), 1 );
            add_action( 'wp_head', array( $this, 'init_js' ), 10 );
            add_action( 'wp_head', array( $this, 'yolink_css' ) );
            add_filter( 'the_content', array( $this, 'add_permalink_for_yolink_use' ) );
            add_filter( $this->yolink_insert_results_hook('the_excerpt'), array( $this, 'add_permalink_for_yolink_use' ) );
            
            # -- 1.0.3 Frugal Compatibility 
            add_filter( $this->yolink_insert_results_hook('frugal_hook_after_headline'), array( $this, 'add_permalink_for_yolink_use' ) );
            
            # Thesis Compatibility
            add_action( 'thesis_hook_after_post_box', array( $this, 'add_permalink_for_yolink_use_for_thesis') );
            
            # Thematic Compatibility
            add_filter( 'thematic_search_form', array( $this, 'get_search_form' ), 50 );
            
            # Other themes cleanr, vigilance, default, etc 
            $this->add_hook_for_non_excerpt_themes();
        }
		foreach( $this->allowed_post_types as $post_type )
			add_action( 'publish_' . $post_type, array( $this, 'submit_post_for_crawl' ), 1, 2 );
	}
       
    /**
    * The plugin implementor should determine when to remove the hook but we want to make it easier for people so we only add this title hook
    * So we are adding this function to support those themes that do not contain typical hooks.
    * @since 1.0.2
    * @return      void
    */	
	function add_hook_for_non_excerpt_themes()
	{
		$curTheme = strtolower(get_current_theme() );
      	if( $curTheme === 'cleanr' || $curTheme === 'default' || $curTheme === '9ths current' || $curTheme === 'blueberry' )
		{
			add_filter( $this->yolink_insert_results_hook('the_title'), array( $this, 'add_permalink_for_yolink_use' ) );	
		}
		else if( $curTheme === 'vigilance' )
		{
			add_filter( $this->yolink_insert_results_hook('the_time'), array( $this, 'add_permalink_for_yolink_use' ) );	
		}
	}

	function yolink_insert_results_hook( $hook )
	{
		return apply_filters( 'yolink_insert_results_hook', $hook );
	}
	
	function init_js()
	{
		if( !is_search() ) 
			return false;
		wp_register_script( 'jquery-tl', 'http://cloud.yolink.com/yolinklite/js/tigr.jquery-1.4.2-min.js', array() );
		wp_register_script('yolink', 'http://cloud.yolink.com/yolinklite/js/v2/yolink-2.0.js', array('jquery-tl') );
		wp_enqueue_script('jquery-tl');
		wp_enqueue_script('jquery');
		wp_enqueue_script('yolink');
		wp_print_scripts();
		
		$urls = array();
		if( get_transient( 'yolink_search_results_' . get_search_query() ) )
		{
			$results = get_transient( 'yolink_search_results_' . get_search_query() );
		}
		else
		{
			$results = $this->do_search( get_search_query() );
		}
		foreach( $results as $post_id )
			$urls[] = "'" . get_permalink( $post_id ) . "'";

		$urls = implode( ',', $urls );
		
		$yolink_config = get_option('yolink_config');
		
		?>
			<script type="text/javascript"> 
			tigr.yolink.Widget.initialize(
			{
			maxResults : <?php echo $this->max_results ?>,
			selectAll: true,
			display : 'embed',
			formfactor : '<?php echo $this->use_mini_share ?>',
			getSearch : function() {
			  var urls = [<?php echo $urls ?>];
			  var matched =  $tigr('a.yolink-href-key').filter( function(x) {
                  var r = false;
                  var that = this;
			      $tigr(urls).each( function(idx,value) {
			         r = r || value == $tigr(that).attr('href');
			      });
			      return r;
              });
              var searchLinks = new Array();
              $tigr(matched).each( function(idx,value)
                  {
                      searchLinks.push( $tigr(value).parent() );   
                  } );
              return searchLinks;
			},
			keywords : '<?php echo html_entity_decode( get_search_query() ) ?>', // the keyword will be pulled from the s input box of the search term
			showTools : 'result',
				<?php 
				echo ( $yolink_config['active_services']['share'] == 'true' ) ? 'share : true,' : 'share : false,';
				echo "\n";
				echo ( $yolink_config['active_services']['googledocs'] == 'true' ) ? 'googledocs : true,' : 'googledocs : false,';
				echo "\n";
				echo ( $yolink_config['active_services']['fblike'] == 'local' ) ? 'fblike : "local",' : '';
				echo "\n";
				echo ( $yolink_config['active_services']['tweet'] == 'local' ) ? 'tweet : "local",' : '';
				echo "\n";
				?>
			preview : 'tab',
			auto : true,
			apikey : '<?php echo $this->api_key ?>',
			showHide : true
			});
			</script>
		<?php
	}
	
	function urlencode( $string )
    {   
        return urlencode( html_entity_decode( $string ) );
    }
	
	function yolink_css()
	{
		if( !is_search() )
			return false;
		?>
		<style type="text/css">
			a.yolink-href-key { display:none }
			.yolink-widget-result { padding: 0 10px; background: #efefef; border: 1px solid: #eee}
				.yolink-widget-result h4 { font-style:italic; font-weight:bold;}
			.yolink-results-logo { padding-left:2px; height:20px; vertical-align: middle;}
		</style>
		<?php
	}
	
	function sanitize_excerpt( $text ) 
	{
		if( !is_search() )
			return $text;
			
		return str_replace( $this->results_title . '#', '', $text );
	}
	
	function add_permalink_for_yolink_use_for_thesis()
	{
		global $post;
		if( !is_search() )
			return false;
    		echo '<div class="yolink-widget-result"><a class="yolink-href-key" href="' . get_permalink( $post->ID ) . '">' . esc_html( $post->post_title ) . '</a></div>';
	}
	
	function add_permalink_for_yolink_use( $content )
	{
		global $post;
		if( !is_search() )
			return $content;
		$content .= '<div class="yolink-widget-result"><a class="yolink-href-key" href="' . get_permalink( $post->ID ) . '">' . esc_html( $post->post_title ) . '</a></div>';
	 	return $content;
	}
	
	function throttle_yolink_index_rate()
	{
		$yolink_config = get_option('yolink_config');
		if( !isset( $yolink_config['yolink_apikey'] ) )
			return false;
			
		$api_url = 'http://index.yolink.com/index/define?ak=' . $yolink_config['yolink_apikey'];
		$request = new WP_Http;
        # --1.0.4  made a fix to the way we sent the delay and ignore robots flag to the server. The prior way sent null		
        $args = array(
            'headers' => array( 'Content-Type' => 'application/json; charset=utf-8'),
            'body'    => '{"ignore-robots":' . $this->ignore_robots . ', "crawl-delay":' . $this->throttle_rate . ',"root" :{}}' 
        );
		$out = $request->post( $api_url, $args );	
	}
	
	function admin_page()
	{
       ?>
		<div class="wrap">
		<?php
		
			echo '<h2 style="font-style:normal;"><img src="http://www.yolink.com/yolink/images/yolink_logo.png" alt="yolink" style="vertical-align:text-bottom; height:25px; margin-right:7px" />' . __('Search', 'yolink') . '</h2>';
			echo '<h3>' . __('yolink Account Integration', 'yolink') . '</h3>';
		?>
			<form action="" method="post" id="yolink-auth">
				<?php
				wp_nonce_field('yolink_registration');
				
				$yolink_config = get_option('yolink_config');
				
				if( !isset( $yolink_config['yolink_apikey']) )
				{
					
					?>
                    <iframe frameborder="1" src="http://www.yolink.com/yolink/legal/yolink_plugin_API_license_agreement_iframe.jsp" width="80%" height="300" style="margin-bottom:15px;border:1px solid #DDDDDD;">
                        <p>Your browser does not support iframes.</p>
                    </iframe><br/>
                    <script type="text/javascript">
                        function toggleRegistration()
                        {
                            if( jQuery(jQuery('#agree-terms')).attr('checked') && jQuery('#registerMe').is(':hidden') )
                            {
                                jQuery('#registerMe').show();
                            }
                            else
                            { 
                                jQuery('#registerMe').hide();
                            }                     
                        }
                    </script>
                    <input style="margin-left:20px;" type="checkbox" onClick="toggleRegistration()" name="agree" id="agree-terms"  value="true" /> Check to Agree to the License Agreement</input>
                    <span style="margin-left:50px"><a href="http://www.yolink.com/yolink/legal/yolink_plugin_API_license_agreement_iframe.jsp" target="_blank">Click here for Print Friendly Version</a></span><br />      
                    <div id="registerMe" style="display:none">
                    <?php
				    	printf(__('<p>By clicking Agree, the administrative email for this blog %s, will be shared with TigerLogic.</p>', 'yolink'), get_option('admin_email') );
                    ?>
                        <p class="submit" >
                            <input type="submit" name="submit" id="submit" class="button-primary" value="<?php _e('Agree', 'yolink') ?>" />
                            <input type="hidden" name="yolink-action" id="yolink-action" value="save_auth" />
                        </p>
                    </div>
					<?php
				}
				else
				{
					$this->throttle_yolink_index_rate();
					printf(__('<p> The API key for this blog is <strong>%s</strong>.</p>', 'yolink'), $yolink_config['yolink_apikey'] );
                ?> 
                <div id="isRegistered"></div>
                <script type="text/javascript">
                        function hasRegistered()
                        {
                            var registered = false;
                            jQuery.ajax(
                            {
                                url : 'http://signup.yolink.com/yoadmin/rpcservices',
                                async : true,
                                dataType : 'jsonp',
                                data : 'o={\"method\":\"get-userinfo\",\"id\":1,\"params\":[{\"apikey\":\"<?php echo $yolink_config['yolink_apikey']?>\"}]}',
                                success : function(ret)
                                {
                                    jQuery.each( ret.result.product, function(idx, val)
                                    {
                                        if( val.name === 'cloud' && ( val.index && parseInt(val.index) > 50))
                                        {
                                            jQuery('#isRegistered').html('Click <a href="http://admin.yolink.com/account" target="_blank">here</a> to manage your account');
                                            registered = true;
                                            return false;
                                        }
                                    });
                                },
                                error: function(reqObj, err,reason)
                                {
                                    registered = false;
                                }
                            } );
                            if( !registered )
                            {
                                jQuery('#isRegistered').html('This unregistered API key is limited to 50 indexed pages. <a href="http://www.yolink.com/yolink/pricing/index.jsp?ak=<?php echo $yolink_config['yolink_apikey']?>" target="_blank">Click here</a> to register the API key to increase the number of permitted indexed pages. yolink search for WordPress is free for personal sites, and pricing for businesses start at $5 per month.');
                            }
                        }
                        hasRegistered();
                </script>
                <?php
				}
				?>
			</form>
			<?php
			if( isset( $yolink_config['yolink_apikey'] ) )
			{
				?>
				<h3><?php _e('Crawl Content', 'yolink' ) ?></h3>
				<p><?php _e('Default selection is both posts and pages.  Please uncheck a type if you do not want it crawled.', 'yolink'); ?></p>
				<form action="" method="post" id="yolink-crawl-form">
					<?php wp_nonce_field('yolink_crawl'); ?>
					<p><?php _e('Search which post types', 'yolink') ?></p>
					<table class="form-table">
				<?php
					$post_types = get_post_types();
					foreach( $this->never_include_post_types as $never )
					{
						unset( $post_types[array_search( $never, $post_types )] );
					}
				
					foreach( $post_types as $post_type )
					{
						if( in_array( $post_type, $this->allowed_post_types ) )
						{
							echo '<tr valign="top"><th scope="row">' . ucwords( $post_type ) . '</th><td><input type="checkbox" name="post-type-crawl[]" id="post-type-' . $post_type . '" checked="checked" value="' . $post_type . '" /></td></tr>';
						}
						else
						{
							echo '<tr valign="top"><th scope="row">' . ucwords( $post_type ) . '</th><td><input type="checkbox" name="post-type-crawl[]" id="post-type-' . $post_type . '" value="' . $post_type . '" /></td></tr>';
						}
					}
				?>
					</table>
					<p class="submit">
						<input type="submit" name="crawl-submit" id="crawl-submit" class="button-primary" value="<?php _e('Crawl','yolink') ?>" />
						<input type="hidden" name="yolink-action-crawl" id="yolink-action-crawl" value="yolink_crawl" />
					</p>
				</form>
				<h3><?php _e('yolink Sharing Services', 'yolink' ); ?></h3>
				<form action="" method="post" id="yolink-service-form">
					<?php wp_nonce_field('yolink_services'); ?>
					<p><?php _e('Please select the sharing service(s) to include with yolink search results.', 'yolink') ?></p>
					<table class="form-table">
					<?php
					$services = array( 
						'share' => __('Share', 'yolink'), 
						'googledocs' => __('Google Docs', 'yolink'), 
						'fblike' => __('Facebook Like Button', 'yolink'),
						'tweet' => __('Twitter', 'yolink')
					);
					foreach( $services as $service => $display ) :
					?>
						<tr valign="top">
							<th scope="row"><?php echo $display ?></th>
						<?php
						$iftrue = array( 'share' => 'true', 'googledocs' => 'true', 'fblike' => 'local', 'tweet' => 'local' );
						if( $yolink_config['active_services'][$service] == $iftrue[$service] )
						{
					?>							
							<td><input type="checkbox" name="yolink_service[<?php echo $service ?>]" id="service-<?php echo $service ?>" checked="checked" value="<?php echo $service; ?>" /></td>
					<?php
						}
						else
						{
							?>
							<td><input type="checkbox" name="yolink_service[<?php echo $service ?>]" id="service-<?php echo $service ?>" value="<?php echo $service; ?>" /></td>
							<?php
						}
					?>
						</tr>
						<?php
					endforeach;
					?>
					</table>
					<p class="submit">
						<input type="submit" name="yolink-services-submit" id="yolink-services-submit" class="button-primary" value="<?php _e('Save Settings','yolink') ?>" />
						<input type="hidden" name="yolink-action-services-submit" id="yolink-action-services-submit" value="yolink_services_submit" />
					</p>
				</form>
			<?php
			}
			?>
		</div>
		<?php
	}
	
	function save_services_settings()
	{
		if( !isset( $_POST['yolink-action-services-submit'] ) )
			return false;
		
		check_admin_referer('yolink_services');
		$yolink_config = get_option('yolink_config');
		$service_status = array();
		$service_status['share'] = ( in_array( 'share', $_POST['yolink_service'] ) ) ? 'true' : 'false';
		$service_status['googledocs'] = ( in_array( 'googledocs', $_POST['yolink_service'] ) ) ? 'true' : 'false';
		$service_status['fblike'] = ( in_array( 'fblike', $_POST['yolink_service'] ) ) ? 'local' : '';
		$service_status['tweet'] = ( in_array( 'tweet', $_POST['yolink_service'] ) ) ? 'local' : '';
			$yolink_config['active_services'] = $service_status;
		update_option( 'yolink_config', $yolink_config );
	}
	
	function _yolink_query_where( $query ) {
		if ( $query->is_search && class_exists( 'YolinkSearch' ) ) {
			if( !$results = get_transient( 'yolink_search_results_' . $_GET['s'] ))
			{
				$results = $this->do_search( get_search_query() );
			}
			
			
			$query->set( 'post__in', $results);
			$query->set( 'post_type', $this->allowed_post_types );
		}
		return $query;
	}
	
	function _yolink_query_order( $order_by ) {
		
		if( !is_search() )
			return $order_by;
		
		global $wpdb, $wp_query;
		$fields = implode( ',', $wp_query->query_vars['post__in'] );
		$order_by = "FIELD($wpdb->posts.ID, $fields)";
		return $order_by;
	}
	
	function yolink_sql_where( $where )
	{
		if( !is_search() )
			return $where;
			
		$where = preg_replace("#LIKE '(%\w+%)'#", "LIKE '%'", $where );
		return $where;
	}
	
	function search_qv( $qvs )
	{
			
		$qvs[] = 'yolink_search';
		return $qvs;		
	}
	
	function do_search( $term = false )
	{				
		$search_term = false;
		if( $term )
			$search_term = stripslashes($term);
		else
		{	
			$search_form_value = get_search_query();
			if( !$search_term )
				$search_term = $search_form_value;
			else
				return false;
		}
		$search_results = $this->yolink_search( $search_term );
		set_transient( 'yolink_search_results_' . $search_term, $search_results, 3600 );
		$this->search_post_ids = $search_results;
		return $search_results;
	}
	
	function crawl_notices()
	{
		if( !isset( $_POST['yolink-action-crawl'] ) )
			return false;
			
		echo '<div class="updated fade"><p>' . __( '<strong>yolink Notice:</strong> The content selected has been submitted. Processing time will vary depending on the amount of content.', 'yolink' ) . '</p></div>';
	}
	
	function crawl_submit( $post_id = false)
	{
		global $wpdb;
		// Determine if we're only submitting a single URL
		$is_single = false;
		if( isset( $post_id ) )
			$is_single = true;
		
		// If this is a multi-URL batch, make sure we have our special field. Ignored for single URL submits
		if( !isset( $_POST['yolink-action-crawl'] ) && !$is_single )
			return false;

		$yolink_config = get_option('yolink_config');		
		if( isset( $_POST['yolink-action-crawl'] ) )
		{	
			if( $post_id )
			{
				$query = new WP_Query( array(
					'post__in'		=> (int) $post_id,
					'showposts'		=> 1,
					'post_status'	=> 'publish',
					'post_type'		=> $this->allowed_post_types,
					)
				);
				while( $query->have_posts() ) : $query->the_post();
					$post_ids[] = $query->post->ID;
				endwhile;
			}
			else
			{
				check_admin_referer('yolink_crawl');
				$combined_posts = array();
				$this->allowed_post_types = (array) $_POST['post-type-crawl'];
				$yolink_config['allowed_post_types'] = $this->allowed_post_types;
				update_option('yolink_config', $yolink_config );
				$post_type_in = array();
				foreach( $this->allowed_post_types as $post_type )
				{
					$post_type_in[] = '"' . $post_type . '"';
				}
				$post_type_in = '(' . implode(',', $post_type_in) . ')';
				$post_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM $wpdb->posts WHERE post_status='publish' AND post_type IN $post_type_in" ) );
				for( $i=0; $i<=$post_count; $i=$i+100 )
				{
					$query = new WP_Query( array(
						'post_type'		=> $this->allowed_post_types,
						'showposts'	=> 100,
						'post_status'	=> 'publish',
						'offset'		=> $i,
						)
					);
					while( $query->have_posts() ) : $query->the_post();
						$post_ids[] = $query->post->ID;
					endwhile;
				}
			}
			
			$post_ids = (object) $post_ids;
		}

        if( is_int( $post_id ) )
		{
	
			$post_ids = (object) array( $post_id );
		}
		// Generate permalink list
		$permalinks = array();
		
		if( !isset( $post_ids) )
			return false;
			
		if( is_array( $post_ids ) )
			$post_ids = (object) $post_ids;
			
		foreach( $post_ids as $post_id )
		{
			$postdata = array(
				'url'			=> get_permalink( $post_id ),
				'depth'			=> 0,
				'annotation'	=> (object) array( 'wp_post_id' => $post_id ),
			);
			$permalinks['urls'][] = $postdata;
		}
		$json_object = json_encode($permalinks);
		if( is_wp_error( $json_object ) )
			return false;
			
		if( $post_ids )
			$json_out = $this->submit_crawl( $json_object );
		
	}
	
	function submit_crawl( $postdata )
	{
		$yolink_config = get_option('yolink_config');
		
		$api_url = 'http://index.yolink.com/index/crawl?o=JSON&ak=' . $yolink_config['yolink_apikey'];
		$request = new WP_Http;
		$args = array(
			'headers'		=> array( 'Content-Type' => 'application/json; charset=utf-8'),
			'body'			=> $postdata,
		);
		$out = $request->post( $api_url, $args );
	}
	
	function submit_post_for_crawl( $post_id, $post )
	{		
		if( in_array( $post->post_type, $this->never_include_post_types ) )
			return false;
		$this->crawl_submit( $post_id );
	}
	
	function add_menu()
	{
		add_menu_page( __('yolink Search', 'yolink'), __('yolink Search', 'yolink'), 'manage_options', 'yolink', array( $this, 'admin_page') , WP_PLUGIN_URL . '/yolink-search/images/yolink-icon.jpg' );
	}
	
	function save_registration()
	{
		if( !isset( $_POST['yolink-action'] ) )
			return false;
		
		check_admin_referer('yolink_registration');
		
		$json_object = $this->yolink_register() ;
		$response_body = json_decode( $json_object[0]['body'] );
		
		if( !get_option('yolink_config') || !is_array( get_option('yolink_config') ) )
			return false;
		
		update_option( 'yolink_config', array_merge( get_option('yolink_config'), array( 'yolink_apikey' => $response_body->apikey, 'yolink_user_url' => $response_body->id ) ) );
	}
	
	function save_options()
	{
		if( isset( $_POST['yolink-action-options'] ) && !check_admin_referer('yolink_config') )
			return false;
		
		$options = array();	
		$options = get_option('yolink_config');
		$old_options = $options;
		$use_extended = ( isset( $_POST['yolink_extended_show'] ) ) ? '1' : '0';
		$options['use_extended'] = $use_extended;
		update_option('yolink_config', $options );
	}
	
	function yolink_register()
	{
		$url = 'http://signup.yolink.com/yoadmin/';
		$method = 'register-external-user';
		$user_object = array();
		$user_object = (object) $user_object;
		$parameters = array(
			'e'	=> get_option('admin_email'),
			'p'	=> 'wpengine.com',
			'o'	=> 'json',
			'id' => parse_url( get_option('siteurl'), PHP_URL_HOST ) . '_' . str_replace( array( '@', '.', '+' ), array( '_at_', '_dot_', '_plus_' ), get_option('admin_email') ),
			'i'	=> json_encode( $user_object ),
			'callback' => '',
		);
		$qs = '?';
		foreach( $parameters as $key => $val )
		{
			$qs .= $key . '=' . $val . '&';
		}
		$http = new WP_Http;
		$api_url = $url . $method . $qs;
        $data = $http->get( $api_url );
		return array( $data );
	}
	
	function yolink_search( $term )
	{
		if( !$this->api_key )
			return false;
			
		$url = 'http://index.yolink.com/index/';
		$method = 'search';
		$user_object = array();
		$user_object = (object) $user_object;
		
		$parameters = array(
			'ak'=> $this->api_key,
			'q'	=> $term,
			'o'	=> 'json',
			'c' => get_option('posts_per_page'),
			'callback' => '',
		);
		$qs = '?';
		foreach( $parameters as $key => $val )
		{
			$qs .= $key . '=' . $this->urlencode( $val ) . '&';
		}
		
		$api_url = $url . $method . $qs;
				
		$http = new WP_Http;
		$args = array(
			'headers'		=> array( 'Content-Type' => 'application/json; charset=utf-8')
		);
		$out_raw = $http->get( $api_url );
		$out = json_decode($out_raw['body']);
		$results = array();
		foreach( $out->urls as $result )
		{
			$results[] = $result->annotation->wp_post_id;
		}
		return $results;
	}
	
	function get_search_form( $form )
	{
		$form = '<form role="yolink_search" method="get" id="yolink_searchform" action="' . home_url( '/' ) . '" >
		<div><label class="screen-reader-text" for="s">' . __('Search for:', 'yolink') . '</label>
		<input type="text" value="' . get_search_query() . '" name="s" id="s" />
		<input type="submit" id="searchsubmit" value="'. esc_attr__('Search', 'yolink') .'" />
		<cite style="float:right; margin-right:20px">' . __('Powered by ', 'yolink') . '<a href="http://yolink.com/yolink/plugins/whichplugin.jsp" target="_blank"><img src="http://www.yolink.com/yolink/images/yolink_logo.png" alt="' . __('Powered by yolink', 'yolink') . '" width="41" height="15" style="vertical-align:text-bottom;"/></a>
		</div>
		</form>';
		return $form;
	}
	
	function wp_http_request_log( $response, $type, $transport=null ) {
		if ( $type == 'response' ) {
			$debug = "$transport: {$response['response']['code']} {$response['response']['message']}";
			foreach ( $response['headers'] as $header => $value )
				$debug .= "\t". trim( $header ) . ': ' . trim($value);
			if ( isset($response['body']) )
				$debug .= ( "Response body: " . trim($response['body']) );
			$this->http_requests[] = $debug;
		}
	}

	function wp_http_response_log( $response, $r, $url ) {

		$debug = "{$r['method']} {$url} HTTP/{$r['httpversion']}";
		$debug .= "{$response['response']['code']} {$response['response']['message']}";
		$this->http_requests[] = $debug;
		return $response;
	}

}
