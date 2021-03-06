<?php
/*
Plugin Name: WHMCS Multisite Provisioning
Plugin URI: http://premium.wpmudev.org/project/whmcs-multisite-provisioning/
Description: This plugin allows remote control of Multisite provisioning from WHMCS. Includes provisioning for Subdomain, Subdirectory or Domain Mapping Wordpress Multisite installs.
Author: WPMU DEV
Author Uri: http://premium.wpmudev.org/
Text Domain: mrp
Domain Path: languages
Version: 1.1.0.8
Network: true
WDP ID: 264
*/

/*  Copyright 2012  Incsub  (http://incsub.com)

Authors - Arnold Bailey (Incsub), Studio Progressive

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if ( !is_multisite() )
exit( __('The WHMCS Multisite Provisioning plugin is only compatible with WordPress Multisite.', 'mrp') );

define('MRP_VERSION','1.1.0.8');

$whmcs_multisite_provisioning = new WHMCS_Multisite_Provisioning();

class WHMCS_Multisite_Provisioning{

	//holds current datapacket from WHMCS
	public $whmcs = null;

	//Settings from 'mrp_settings');
	public $settings = '';

	//Response values sent back to WHMCS
	public $response = array();

	//Is Domain mapping
	public $domain_mapping_active = false;

	//Pointer to $wpdb;
	public $db;

	// Domain Mapping table
	public $dmt = '';

	/**
	* CONSTRUCTOR
	*
	*/
	function __construct(){
		global $wpdb;

		//Hookup domain mapping database
		$this->domain_mapping_active = class_exists('domain_map') || function_exists('dm_sunrise_warning');
		if($this->domain_mapping_active){
			// Hook up the domain mapping table
			$this->db =& $wpdb;

			if(!empty($this->db->dmtable)) {
				$this->dmt = $this->db->dmtable;
			} else {
				if(defined('DM_COMPATIBILITY')) {
					if(!empty($this->db->base_prefix)) {
						$this->db->dmtable = $this->db->base_prefix . 'domain_mapping';
					} else {
						$this->db->dmtable = $this->db->prefix . 'domain_mapping';
					}
				} else {
					if(!empty($this->db->base_prefix)) {
						$this->db->dmtable = $this->db->base_prefix . 'domain_map';
					} else {
						$this->db->dmtable = $this->db->prefix . 'domain_map';
					}
				}
			}
		}

		register_activation_hook(__FILE__,array($this,'on_activate'));
		register_deactivation_hook(__FILE__,array($this,'on_deactivate'));

		add_action('init', array($this,'on_init'));
		add_action('admin_init', array($this,'on_admin_init'));
		add_action('network_admin_menu', array($this,'on_network_admin_menu'));

		add_filter('query_vars', array($this,'on_query_vars'));
		add_action('parse_request', array($this,'on_parse_request'));
	}

	/**
	* on_activate - Called on plugin activation. Does any initial setup
	*
	*/
	function on_activate(){
		//Activation if needed.
	}

	/*
	function set_extend($blog_id) {
	//$trial_days = $this->get_setting('trial_days');
	if ( $trial_days > 0 ) {
	$extend = $trial_days * 86400;
	$this->extend($blog_id, $extend, 'Trial', $this->get_setting('trial_level', 1));
	}
	}
	*/

	/**
	* on-deactivate - called on deactivating the plugin. Performs any cleanup necessary
	*
	*/
	function on_deactivate(){
		//Deactivation if needed.
	}
	/**
	* on_init -  Calls init hook functions.
	*
	*/
	function on_init(){
		load_plugin_textdomain( 'mrp', false, '/languages/' );
		include_once ABSPATH . '/wp-admin/includes/ms.php';
	}

	/**
	* on_admin_init -
	*
	*/
	function on_admin_init(){
		register_setting('mrp','mrp_settings');
	}

	/**
	* on_network_admin_menu - Add network menu for this plugin
	*
	*/
	function on_network_admin_menu() {
		if( function_exists( 'add_menu_page' ) ){
			add_menu_page(__('WHMCS Provisioning','mrp'), __('WHMCS Provisioning','mrp'), 'manage_network_options', 'mrp-settings',array($this,'admin_settings_page'));
		}
	}

	/**
	* on_query_vars - Authorize query vars for this plugin
	*
	*/
	function on_query_vars($vars){
		//Add any vars your going to be receiving from WHMCS
		$vars[] = 'whmcs'; //WHMCS data array
		return $vars;
	}

	/**
	* on_parse_request - See if the Query is for us
	*
	*/
	function on_parse_request($wp){
		global $current_user;

		// If no whmcs data then not for us
		if(! array_key_exists('whmcs',$wp->query_vars)) return;


		$this->whmcs = $_REQUEST['whmcs'];

		$this->response = array(); //Values to be returned to whmcs'

		$this->settings = get_site_option('mrp_settings');

		//Start the buffer so any extraneous error messages generated by debug errors won't get sent.
		if( ! $this->settings['debug']) ob_start();


		// Get valid IPs we will accept requests from and trim and remove any blank lines.
		$this->ips = array_filter( array_map('trim',explode("\n",$this->settings['ips'])), create_function('$str','return !empty($str);'));;

		//See if we're ready and authorized to process requests
		$user = wp_signon($this->whmcs['credentials'], false);
		if( ! is_wp_error($user)) wp_set_current_user($user->ID);

		if(is_wp_error($user)){
			$this->response['error'] = strip_tags($user->get_error_message());
		}
		elseif((count($this->ips) > 0) && ! in_array($_SERVER['REMOTE_ADDR'], $this->ips)){
			$this->response['error'] = __('This request was not from an authorized site:','mrp') . $_SERVER['REMOTE_ADDR'];
		}
		elseif(! current_user_can('manage_site')){
			$this->response['error'] = __('You do not have permission to access this function.','mrp');
		}elseif(! is_array($this->whmcs) ){
			$this->response['error'] = __('You can\'t create an empty site.', 'mrp');
		}else{
			$this->process_request();
		}

		//Throw away any extraneous error messages
		if( ! $this->settings['debug']) ob_end_clean();

		exit(json_encode($this->response) );
	}

	/**
	* process_request - validated request in $this->whmcs
	*
	*/
	function process_request(){

		switch ($this->whmcs['action']){
			case 'create' : $this->create_blog(); break;
			case 'suspend' : $this->suspend_blog(); break;
			case 'unsuspend' : $this->unsuspend_blog(); break;
			case 'terminate' : $this->terminate_blog(); break;
			case 'password' : $this->set_password(); break;
			case 'changepackage' : $this->changepackage(); break;

			default: $this->response['error'] = __('No valid action request.', 'mrp'); break;
		}
	}

	/**
	* create_blog -  Creates a new Multisite blog using the parameters passed from whmcs
	* $this->whmcs contains (at least)
	* ['action']
	* ['domain']
	* ['title']
	* ['email']
	* ['user_name']
	* ['password']
	* ['last_name']
	* ['first_name']
	* ['nickname']
	* ['default_role']
	* ['upload_space']
	* ['credentials']
	*
	* @todo Honor the registration settings in WP Network settings. none user blog or all
	*/
	function create_blog(){
		global $wpdb,$current_user,$current_site,$base;

		$domain = strtolower($this->whmcs['domain']);
		$mapped_domain = strtolower($this->whmcs['mapped_domain']);

		if( trim($domain) . trim($mapped_domain) == '') {
			$this->response['error'] = "$domain: " . __('Domain name is empty!', 'mrp');
			return;
		}

		if (! preg_match('|^([a-zA-Z0-9-])+$|', $this->whmcs['domain'])){
			$this->response['error'] = "$domain: " . __('Is not a valid domain name, alphanumeric and "-" only', 'mrp');
			return;
		}

		// If not a subdomain install, make sure the domain isn't a reserved word
		if ( ! is_subdomain_install() ) {
			$subdirectory_reserved_names = apply_filters( 'subdirectory_reserved_names', array( 'page', 'comments', 'blog', 'files', 'feed' ) );
			if ( in_array( $domain, $subdirectory_reserved_names ) ){
				$this->response['error'] = sprintf( __('The following words are reserved for use by WordPress functions and cannot be used as blog names: <code>%s</code>' ), implode( '</code>, <code>', $subdirectory_reserved_names ) ) ;
				return;
			}
		}


		//Is there already a domain with this mapping?
		//		if($this->domain_mapping_active){
		//			if(
		//			//Check blogs table
		//			null != $this->db->get_row( $this->db->prepare("SELECT blog_id FROM {$this->db->blogs} WHERE domain = %s AND path = '/' /* domain mapping */", $mapped_domain) )
		//
		//			//Check mapped table
		//			|| null != $this->db->get_row( $this->db->prepare("SELECT blog_id FROM {$this->dmt} WHERE domain = %s /* domain mapping */", $mapped_domain ) ) ) {
		//				$this->response['error'] = __("Mapped domain already exists for ",'mrp') . $mapped_domain;
		//				return;
		//			}
		//		}

		$email = sanitize_email( $this->whmcs['email'] );
		$user_id = email_exists($email);


		if($user_id){
			$user_name = get_userdata($user_id)->user_login; // Can't change name so pass back to update WHMCS
		} else{
			$user_name = (empty($this->whmcs['user_name'])) ? sanitize_user( strstr($email, '@', true) ) : sanitize_user($this->whmcs['user_name'], true);
			$ndx = 1;
			$un = $user_name;
			while($user = get_user_by('login', $user_name)){
				$user_name = $un . $ndx++; //Avoid name collision
			}
		}

		$this->response['user_name'] = $user_name; //Send back to WHMCS
		$password = $this->whmcs['password'];

		$title = (empty($this->whmcs['title']) ) ? '' : $this->whmcs['title'];

		$credentials = $this->whmcs['credentials'];


		if ( empty( $domain ) ){
			$this->response['error'] = __( 'Missing or invalid site address.','mrp');
			return;
		}
		if ( empty( $email ) ){
			$this->response['error'] = __( 'Missing email address.','mrp');
			return;
		}
		if ( !is_email( $email ) ){

			$this->response['error'] = __( 'Invalid email address.','mrp');
			return;
		}

		if ( is_subdomain_install() ) {
			//Subdomain Install
			$this->response['install_type'] = 'subdomain';
			$newdomain = $domain . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$path = $base;

			//Check for duplicate
			$ndx= 1;
			$nd = $newdomain; //Remember the originl $newdomain
			$blog_details = get_blog_details(array('domain' => $newdomain, 'path' => $path));
			while(! empty($blog_details)){
				//				$whmcs_settings = get_blog_option($blog_details->blog_id,'whmcs_settings');
				//				if ( $whmcs_settings && $whmcs_settings['client_id'] == $credentials['whmcs_client_id']){	//Found owner of this blog
				//					break;
				//				}
				$newdomain = str_replace($domain, $domain . $ndx++, $nd);
				$blog_details = get_blog_details(array('domain' => $newdomain, 'path' => $path));
			}
		} else {
			//Subdirectory Install
			$this->response['install_type'] = 'subdirectory';
			$newdomain = $current_site->domain;
			$path = trailingslashit($base) . trailingslashit($domain);

			//Check for duplicate
			$ndx= 1;
			$p = $path; // remember original path
			$blog_details = get_blog_details(array('domain' => $newdomain, 'path' => $path));
			while(! empty($blog_details)){  //Already there
				//				$whmcs_settings = get_blog_option($blog_details->blog_id,'whmcs_settings');
				//				if ( $whmcs_settings && $whmcs_settings['client_id'] == $credentials['whmcs_client_id']){	//Found an owner of this blog
				//
				//					break;
				//				}
				$path = str_replace(trim($p,'/'), trim($p,'/') . $ndx++, $p);
				$blog_details = get_blog_details(array('domain' => $newdomain, 'path' => $path));
			}
		}

		//Pass back the new values
		$this->response['domain'] = $newdomain;
		$this->response['path'] = $path;

		if ( !$user_id ) { // Create a new user with WHMCS password
			//$password = wp_generate_password( 12, false );
			$user_id = wpmu_create_user( $user_name, $password, $email );
			if ( false == $user_id ){
				$this->response['error'] = __( 'There was an error creating the user.','mrp' );
				return;
			}
			else {
				if($this->whmcs['last_name']) update_user_option($user_id, 'last_name', $this->whmcs['last_name'], true);
				if($this->whmcs['first_name']) update_user_option($user_id, 'first_name', $this->whmcs['first_name'], true);

				if($this->whmcs['nickname']) update_user_option($user_id, 'nickname', $this->whmcs['nickname'], true);
				else update_user_option($user_id, 'nickname', $this->whmcs['first_name'], true);

				wp_new_user_notification( $user_id, $password );
			}
		} else {
			//Already in database
			$this->response['password'] =__('PREVIOUSLY SET', 'mrp'); //Send back to WHMCS
		}

		//return the login
		$userdata=get_userdata( $user_id );
		$this->response['login']=$userdata->user_login;

		//remove from primary blog
		remove_user_from_blog( $user_id, $current_site->id ); //removes new user from main blog

		$wpdb->hide_errors();
		$id = wpmu_create_blog( $newdomain, $path, $title, $user_id , array( 'public' => 1 ) );

		$wpdb->show_errors();

		//Blog wasn't created
		if ( is_wp_error( $id ) ) {
			$this->response['error'] = $id->get_error_message();
			return;
		}

		$this->response['blog_id'] = $id;  //Send back to WHMCS

		//add default role
		if ($this->whmcs['default_role']) {
			$role_name=$this->whmcs['default_role'];
			$role_slug=str_replace(' ','_',strtolower($role_name));
			$role_slug=preg_replace("/[^a-zA-Z0-9\s]/", "", $role_slug);

			if(!get_role($role_slug)) {
				$roles=new WP_Roles();
				$roles->add_role($role_slug,$role_name,array($role_slug));
			}
			remove_user_from_blog($user_id, $id);
			add_user_to_blog($id, $user_id, $role_slug);
			$user = new WP_User($user_id);
			//$user->add_role($roleSlug);
		}

		if ( !is_super_admin( $user_id ) && !get_user_option( 'primary_blog', $user_id ) )
		update_user_option( $user_id, 'primary_blog', $id, true );

		//Save the WHMCS product data for this blog
		$whmcs_settings = get_blog_option($id,'whmcs_settings');
		if (! $whmcs_settings) $whmcs_settings = array();

		$whmcs_settings['client_id'] = $credentials['whmcs_client_id'];
		$whmcs_settings['service_id'] = $credentials['whmcs_service_id'];
		$whmcs_settings['product_id'] = $credentials['whmcs_product_id'];

		update_blog_option($id, 'whmcs_settings', $whmcs_settings);

		$content_mail = sprintf( __( "New site created by %1s\n\nAddress: %2s\nName: %3s"), $current_user->user_login , get_site_url( $id ), stripslashes( $title ) );
		wp_mail( get_site_option('admin_email'), sprintf( __( '[%s] New Site Created' ), $current_site->site_name ), $content_mail, 'From: "Site Admin" <' . get_site_option( 'admin_email' ) . '>' );
		wpmu_welcome_notification( $id, $user_id, $password, $title, array( 'public' => 1 ) );
		//wp_redirect( add_query_arg( array( 'update' => 'added', 'id' => $id ), 'site-new.php' ) );

		//If domain mapping, map domain
		if($this->domain_mapping_active){
			if( null == $this->db->get_row( $this->db->prepare("SELECT blog_id FROM {$this->db->blogs} WHERE domain = %s AND path = '/' /* domain mapping */", $mapped_domain) )
			&& null == $this->db->get_row( $this->db->prepare("SELECT blog_id FROM {$this->dmt} WHERE domain = %s /* domain mapping */", $mapped_domain ) ) ) {
				$this->db->query( $this->db->prepare( "INSERT INTO {$this->dmt} ( `id` , `blog_id` , `domain` , `active` ) VALUES ( NULL, %d, %s, '1') /* domain mapping */", $id, $mapped_domain) );
				$this->response['mapped_domain'] = $mapped_domain; // Handshake back that it worked
			}
		}

		//Blog specific stuff
		if (is_numeric($this->whmcs['upload_space'])){
			update_blog_option($id, 'blog_upload_space',intval($this->whmcs['upload_space']));
		}

		//Create the blog uploads directory
		if( ! is_dir( WP_CONTENT_DIR.'/blogs.dir/'.$id . '/files' ) ) {
			mkdir(WP_CONTENT_DIR.'/blogs.dir/'.$id . '/files', 0755, true); //0755 octal
		}


		/**
		* ProSites
		*/
		global $wpdb,$current_user,$current_site,$base, $psts;

		$level = trim( $this->whmcs['level'] );

		if( !empty( $level ) ) { //Need to handle Pro-Sites?
			//Is pro-sites installed?
			if( empty($psts) ) {
				$this->response['error'] = __( 'Requesting Pro-sites which is not active on this site.','mrp');
				return;
			}

			$levels = wp_list_pluck( get_site_option('psts_levels', array() ), 'name' );
			$levels = array_map( 'trim',  $levels );
			if( empty($levels) ){
				//No levels to search
				$this->response['error'] = __( 'No Levels defined in Pro-Sites.','mrp');
				return;
			}

			$level_id = 0;
			foreach($levels as $key => $value) {
				if( strtolower($value) == strtolower($level) ) $level_id = intval($key);
			}

			if(empty($level_id) ) {
				if( ! strtolower( $psts->get_setting( 'free_name' ) ) == strtolower( $level ) ) {
					$this->response['error'] = __( "Invalid Pro Sites Level name in create_blog: $level",'mrp');
					return;
				}
			}
			
			$this->response['level'] = $level_id;

			$ch_blog = $wpdb->get_row("SELECT blog_ID FROM " . $wpdb->base_prefix . "pro_sites WHERE blog_ID=$id LIMIT 1");
			if(!empty($ch_blog->blog_ID)){
				$wpdb->query($wpdb->prepare("UPDATE " . $wpdb->base_prefix . "pro_sites SET level=%d WHERE blog_ID=%s", $level_id, $id));
				$psts->record_stat($id, 'upgrade');
			} else {
				$wpdb->query($wpdb->prepare("INSERT INTO " . $wpdb->base_prefix . "pro_sites (blog_ID, expire, level, gateway, term) VALUES (%d, '9999999999', %s, 'WHMCS', 'Permanent')", $id, $level_id));
				$psts->record_stat($id, 'signup');
			}
			$psts->log_action($id, __("WHMCS created blog id {$id}. Expiration and payments will be handled by WHMCS", 'mrp') );

		}
		//print_r($this->db);
		//exit();
		//All done
		$this->response['success'] = true;
	}


	///// Change LEVEL BLOG
	/**
	* changepackage - command to suspend a blog from WHMCS
	* $this->whmcs contains (at least)
	* ['action']
	* ['domain']
	* ['credentials']
	* @since 1.1
	*/
	function changepackage(){
		global $wpdb,$base, $psts;

		$id = intval($this->whmcs['blog_id']);
		$domain = $this->whmcs['domain'];
		$details = get_blog_details($id);
		$level = $this->whmcs['level'];

		if( !empty( $level ) ) {

			if( empty($psts) ) {
				$this->response['error'] = __( 'Requesting Pro-sites which is not active on this site.','mrp');
				return;
			}

			$levels = get_site_option('psts_levels', array() );
			if(empty($levels) ) {
				//No levels to search
				$this->response['error'] = __( 'No Levels defined in Pro-Sites.','mrp');
				return;
			}

			$level_id = 0;
			foreach($levels as $key => $val) {
				if( strtolower($val['name']) == strtolower($level) ) $level_id = intval($key);
			}

			if(empty($level_id) ) {
				$this->response['error'] = __( 'Invalid Pro Sites Level name in changepackage.','mrp');
				return;
			}
			$this->response['level'] = $level_id;

			$ch_blog = $wpdb->get_row("SELECT blog_ID FROM " . $wpdb->base_prefix . "pro_sites WHERE blog_ID=$id LIMIT 1");
			if(!empty($ch_blog->blog_ID)){
				$update_level = $wpdb->query($wpdb->prepare("UPDATE " . $wpdb->base_prefix . "pro_sites SET level=%d WHERE blog_ID=%d", $level_id, $id));
			} else {
				$update_level = $wpdb->query($wpdb->prepare("INSERT INTO " . $wpdb->base_prefix . "pro_sites (blog_ID, expire, level, gateway, term) VALUES (%d, '9999999999', %d, 'WHMCS', 'Permanent')", $id, $level_id));
			}

			if( $update_level === false ){
				$this->response['error'] = "$domain: $update_level" . __( "Error SQL Update Level Status $wpdb->last_error $wpdb->last_query",'mrp');
				return;
			}
			$this->response['message'] = "success";
			$psts->record_stat($id, 'upgrade');
			$psts->log_action($id, __("WHMCS changed Pro-Sites level to {$levels[$level_id]['name']} (Level ID {$level_id}).", 'mrp') );
		}

	}

	/**
	* suspend_blog - command to suspend a blog from WHMCS
	* $this->whmcs contains (at least)
	* ['action']
	* ['domain']
	* ['credentials']
	*/
	function suspend_blog(){
		global $psts;


		$id = intval($this->whmcs['blog_id']);
		$domain = $this->whmcs['domain'];

		$details = get_blog_details($id);

		//Does blog exist?
		$this->response['message'] = "[$domain] [$id]";

		if (empty($details)){
			$this->response['error'] = "$domain: " . __( 'domain not found when trying to suspend','mrp');
			return;
		}

		if( !empty($psts) ) $psts->log_action($id, __("WHMCS SUSPENDED blog id {$id}.", 'mrp') );

		update_blog_status( $id, 'deleted', '1' );
	}

	/**
	* unsuspend_blog - command to suspend a blog from WHMCS
	* $this->whmcs contains (at least)
	* ['action']
	* ['domain']
	* ['credentials']
	*
	*/
	function unsuspend_blog(){
		global $psts;

		$id = intval($this->whmcs['blog_id']);
		$domain = $this->whmcs['domain'];

		$details = get_blog_details($id);

		//Does blog exist?
		$this->response['message'] = "[$domain] [$id]";
		if (empty($details)){
			$this->response['error'] = "$domain: " . __( 'domain not found when trying to unsuspend','mrp');
			return;
		}

		if( !empty($psts) ) $psts->log_action($id, __("WHMCS UNSUSPENDED blog id {$id}.", 'mrp') );

		update_blog_status( $id, 'deleted', '0' );
	}

	/**
	* terminate_blog - command to terminate (delete) a blog from WHMCS
	* Once called from WHMCS it can only be revoked by a superadmin on Wordpress. WHMCS cannot change further.
	* $this->whmcs contains (at least)
	* ['action']
	* ['domain']
	* ['credentials']
	*
	*/
	function terminate_blog(){
		global $psts;

		$id = intval($this->whmcs['blog_id']);
		$domain = $this->whmcs['domain'];

		$details = get_blog_details($id);

		//Does blog exist?
		$this->response['message'] = "[$domain] [$id]";

		if (empty($details)){
			$this->response['error'] = "$domain: " . __( 'domain not found when trying to terminate','mrp');
			return;
		}

		if( !empty($psts) ) $psts->log_action($id, __("WHMCS TERMINATED blog id {$id}.", 'mrp') );

		wpmu_delete_blog( $id, true );
	}

	/**
	* set_password - command to set the password for the user on a blog
	* $this->whmcs contains (at least)
	* ['action']
	* ['domain']
	* ['email']
	* ['user_name']
	* ['password']
	* ['credentials']
	*
	*/
	function set_password(){

		$domain = strtolower($this->whmcs['domain']);
		$password = $this->whmcs['password'];
		$email = sanitize_email($this->whmcs['email']);
		$user_name = sanitize_user($this->whmcs['user_name']);
		$user_id = email_exists($email);

		//Does User exist?
		$this->response['message'] = "[$email] [$user_id]";
		if (! $user_id){
			$this->response['error'] = __( "No user found for this $user_name/$email combination",'mrp');
			return;
		}
		wp_set_password($password,$user_id);
	}

	/**
	* admin_settings_page - Displays the Admin settings page.
	*
	*/
	function admin_settings_page(){

		if(! empty($_POST['mrp_wpnonce']) && wp_verify_nonce($_POST['mrp_wpnonce'],'mrp_admin')){
			$settings = $_POST['mrp'];
			update_site_option('mrp_settings', $settings);
			echo '<div class="updated fade"><p>Settings Updated</p></div>';
		}
		$settings = get_site_option('mrp_settings');
		?>
		<div class="wrap">
			<h2><?php _e('WHMCS Multisite Provisioning','mrp'); echo " " . MRP_VERSION; ?></h2>
			<div class="metabox-holder">
				<div class="postbox">
					<div class="inside">
						<form method="POST" action="#">
							<?php wp_nonce_field('mrp_admin','mrp_wpnonce'); ?>
							<h3 class="hndle"><?php _e('WHMCS Multisite Provisioning','mrp'); ?></h3>
							<table class="form-table">
								<thead>
								</thead>
								<tbody>
									<tr>
										<th><?php _e('Remote WHMCS host:','mrp'); ?></th>
										<td><input type="text" name="mrp[remote_host]" size="40" value="<?php echo esc_attr($settings['remote_host']); ?>" /></td>
									</tr>
									<!--
									<tr>
									<th><?php _e('Authentication Token:','mrp'); ?></th>
									<td><input type="text" name="mrp[token]" size="40" value="<?php echo esc_attr($settings['token']); ?>" /></td>
									</tr>
									-->
									<tr>
										<th>
											<?php _e('Authorized IP Addresses:','mrp'); ?><br />
											<?php _e('(one per line)','mrp'); ?><br />
											<?php _e('Leave blank to disable IP filtering','mrp'); ?>
										</th>
										<td><textarea name="mrp[ips]" cols="40" rows="4"><?php echo esc_attr($settings['ips']); ?></textarea></td>
									</tr>

									<tr>
										<th>
											<?php _e('Debug:','mrp'); ?><br />
										</th>
										<td>
											<label for="mrp[debug]" >
												<input type="hidden" name="mrp[debug]" value="0" />
												<input type="checkbox" id="mrp[debug]" name="mrp[debug]" value="1" <?php checked($settings['debug']); ?>/>
												<span class="description">Turn on debugging</span>
											</label>
											<p><span class="description"><?php _e('This plugin prevents sending debug messages generated by other plugins to WHMCS.','mrp'); ?></span></p>
											<p><span class="description"><?php _e('Check debug to allow these message to pass to WHMCS so they can appear in the WHMCS Modules Log there.','mrp'); ?></span></p>
										</td>
									</tr>

								</tbody>
							</table>
							<input type="submit" class="button-primary" name="submit" value="<?php _e(esc_attr('Save Settings'),'mrp'); ?>" />
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}