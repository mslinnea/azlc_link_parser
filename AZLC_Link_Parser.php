<?php
/*
 * Plugin Name: AZLC_Link_Parser
 * Plugin URI: http://www.linsoftware.com/amazon-link-checker/
 * Description: This is a module designed to be used with other plugins.
 * It parses posts for Amazon links and iframe links and stores them in the database.
 * Self contained with the only requirement being AmazonLinkCheckerDatabase.php
 * Version: 1.0.0
 * Author: Linnea Wilhelm, Lin Software
 * Author URI: http://www.linsoftware.com
 */

/**
 * Created by PhpStorm.
 * User: Linnea
 * Date: 9/23/2015
 * Time: 10:20 AM
 */
/*
* Known limitations:
 * Does not parse: Amazon shortlinks, amazon forms
 * Todo:  Need to figure out some way to handle errors
*/

require_once('AmazonLinkCheckerDatabase.php');

if ( ! class_exists( 'AZLC_Link_Parser' ) ) :

class AZLC_Link_Parser {

	private static $done = false;
	private static $version = 100;
	/* @var $database AmazonLinkCheckerDatabase */
	private static $database;

	function __construct() {
		self::$done = get_option('azlc_parser_status', false);
		self::$database = new AmazonLinkCheckerDatabase();
		add_option('azlc_parser_version', self::$version);
		add_action('wp_head', array('AZLC_Link_Parser', 'javascript'));
		add_action( 'publish_post', array( 'AZLC_Link_Parser', 'onPublishPost' ) );
		add_action( 'publish_page', array( 'AZLC_Link_Parser', 'onPublishPost' ) );
		add_action( 'wp_ajax_azlc_link_parse', array('AZLC_Link_Parser', 'ajaxWork') );
		// the delete_post action hook is called when posts and pages are deleted from the trash
		add_action( 'delete_post', array( 'AZLC_Link_Parser', 'onDeletePost' ) );
	}

	public static function done() {
		update_option('azlc_parser_status', true);
		self::$done = true;
	}

	public static function notDone() {
		update_option('azlc_parser_status', false);
		self::$done = false;
	}
	public static function activate() {
		self::$database->install();
	}

	public static function upgrade() {
		if(get_option('azlc_parser_version')!=100) {
			// put upgrade code here
			update_option('azlc_parser_version', 100);
		}
	}

	public static function uninstall() {
		delete_option('azlc_parser_status');
		delete_option('azlc_parser_version');
	}

	public static function onPublishPost($post_ID) {

		self::deleteLinks($post_ID);

		// add links again
		// which is better for performance?
		// a) parse post immediately  b) let the ajax call post the parse on next page load
		// we will use b for now
		self::notDone();
	}

	public static function onDeletePost($post_ID) {
		self::deleteLinks($post_ID);
	}

	public static function deleteLinks($post_ID) {
		/* @var $wpdb WPDB */
		global $wpdb;
		$post_ID_safe = filter_var( $post_ID, FILTER_SANITIZE_NUMBER_INT );
		$wpdb->delete( self::$database->post_status_table, array( 'post_id' => $post_ID_safe ) );
		$wpdb->delete( self::$database->link_instances_table, array( 'post_id' => $post_ID_safe ) );
	}

	public static function ajaxWork() {
			if(self::$done) {
				self::close_ajax(0);
			} else {
				self::parsePost();
				self::close_ajax(1);
			}
	}

	public static function parsePost() {
		// get a post to parse
		$post = self::getNextPost();

		// get the content
		$content = get_post_field( 'post_content', $post->ID );
		$content = do_shortcode( $content );

		// parse post
		// suppress errors
		@$dom = new DOMDocument();
		@$dom->loadHTML($content);
		$xpath = new DOMXPath($dom);
		$links_data = self::extractLinks($xpath);
		$iframe_data = self::extractIframes($xpath);
		$data = array_merge($links_data, $iframe_data);
		// store info in database
		self::saveLinks($data, $post->ID);

		// todo: catch errors
		// update post status table
		self::recordParse(true, $post->ID);
	}

	public static function recordParse($success, $post_ID) {
		/* @var $wpdb WPDB */
		global $wpdb;
		$completed = $success ? 1 : 0;
		$wpdb->insert(
			self::$database->post_status_table,
			array(
				'post_id'      => $post_ID,
				'completed'    => $completed,
				'time_updated' => current_time( 'mysql' )
			) );
	}

	public static function saveLinks($data, $post_ID) {
		/* @var WPDB $wpdb */
		global $wpdb;

		foreach ( $data as $d ) {
			$wpdb->insert(
				self::$database->link_instances_table,
				array(
					'time_updated'  => current_time( 'mysql' ),
					'id'            => uniqid( "amz", true ),
					'post_id'       => $post_ID,
					'post_title'    => get_the_title( $post_ID ),
					'asin'          => $d['asin'],
					'link_type'     => $d['link_type'],
					'link_code'     => $d['link_code'],
					'link_text'     => $d['link_text'],
					'affiliate_tag' => $d['tag'],
					'url'           => $d['url'],
					'region'        => $d['region']
				) );
		}
	}

	/**
	 * @param $xpath DOMXPath
	 *
	 * @return array
	 */
	public static function extractLinks($xpath) {
		$nodes = $xpath->query('//a/@href');
		$i = 0;
		$data = array();
		foreach($nodes as $href) {
			$url =  urldecode($href->nodeValue);
			$url_parts = parse_url( $url );
			if(! array_key_exists('host', $url_parts )) {
				//url does not contain a host name
				// it's not an amazon link, so skip
				continue;
			}
			// periods are added to the search string to avoid returning incorrect urls
			// for example: http://amazon.myblog.com would NOT be a match
			if ( stripos( $url_parts['host'], ".amazon." ) !== false ) {
				$data[ $i ]['url']       = $url ;
				$data[ $i ]['link_type'] = "a";
				$data[ $i ]['asin'] = self::extractAsin($url);
				$data [ $i ]['tag'] = self::extractTag($url);
				$data[$i]['region'] = self::extractRegion($url);
				$data[$i]['link_code'] = null;
				$data[$i]['link_text'] = null;
				$i ++;
			}

		}

		return $data;
	}

	/**
	 * @param $xpath DOMXPath
	 *
	 * @return array
	 */
	public static function extractIframes($xpath) {
		$nodes = $xpath->query('//iframe/@src');
		$i = 0;
		$data = array();
		foreach($nodes as $src) {
			$url =  urldecode($src->nodeValue);
			$url_parts = parse_url( $url );
			if(! array_key_exists('host', $url_parts )) {
				//url does not contain a host name
				// it's not an amazon link, so skip
				continue;
			}
			// periods are added to the search string to avoid returning incorrect urls
			// for example: http://amazon.myblog.com would NOT be a match
			if ( stripos( $url_parts['host'], ".amazon-adsystem." ) !== false ) {
				$data[ $i ]['url']       = $url ;
				$data[ $i ]['link_type'] = "iframe";
				$data[ $i ]['asin'] = self::extractAsin_iframe($url);
				$data [ $i ]['tag'] = self::extractTag_iframe($url);
				$data[$i]['region'] = self::extractRegion_iframe($url);
				$data[$i]['link_code'] = null;
				$data[$i]['link_text'] = null;
				$i ++;
			}

		}

		return $data;
	}


	public static function extractAsin($url) {

		// Many Amazon links have the string /product/ prior to the ASIN
		$begin_product = stripos( $url, "/product/" );

		// Some Amazon links are formatted differently, for example:
		// http://www.amazon.com/Archer-Africa-William-Negley/dp/B001E0VEJ6/ref=sr_1_1?ie=UTF8&qid=1433094556&sr=8-1&keywords=archer+in+africa
		$begin_dp = stripos( $url, "/dp/" );

		$begin_offerlisting = stripos( $url, '/offer-listing/' );

		if ( ! $begin_product === false ) {
			$begin = $begin_product + 9;
		} elseif ( ! $begin_dp === false ) {
			$begin = $begin_dp + 4;
		} elseif ( ! $begin_offerlisting === false ) {
			$begin = $begin_offerlisting + 15;
		}

		if ( ! isset( $begin ) ) {
			return '';
		} else {
			return substr( $url, $begin, 10 );
		}
	}

	public static function extractTag($url) {

		// if no tag in the url, return empty string (maybe NULL is better?)
		if ( stripos( $url, "tag=" ) === false ) {
			return '';
		}

		$begin  = stripos( $url, "tag=" ) + 4;
		$end    = stripos( $url, "-20", $begin ) + 3;
		$length = $end - $begin;

		return substr( $url, $begin, $length );

	}

	public static function extractRegion($url) {
		$url_parts  = parse_url( $url );
		$amazon_pos = stripos( $url_parts['host'], "amazon." );
		if ( $amazon_pos === false ) {
			return '';
		} else {
			$begin = $amazon_pos + 7;

			return substr( $url_parts['host'], $begin );
		}
	}


	public static function extractForms($content) {

	}

	public static function getNextPost() {
		$args = array(
			'posts_per_page'   => - 1,
			'offset'           => 0,
			'category'         => '',
			'category_name'    => '',
			'orderby'          => 'ID',
			'order'            => 'DESC',
			'include'          => '',
			'exclude'          => '',
			'meta_key'         => '',
			'meta_value'       => '',
			'post_type'        => 'any',
			'post_mime_type'   => '',
			'post_parent'      => '',
			'post_status'      => 'publish',
			'suppress_filters' => true
		);

		$posts_array = get_posts( $args );

		$post_ids = array();
		foreach ($posts_array as $p) {
			$post_ids[] = $p->ID;
		}

		foreach ( $posts_array as $p ) {
			if ( self::already_parse( $p->ID ) ) {
				continue;
			} else {
				return $p;

			}
		}
			// if we got here, there are no posts to parse, we're done
			self::done();
			self::close_ajax(0);

	}

	public static function close_ajax($code) {
		echo $code;
		exit();
	}

	public static function javascript() {
	?>
		<script  type="text/javascript">
			var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
			console.log("test 2225");
		var azlc_parser = setInterval(function(){ azlc_parser_go() }, 3000);

		function azlc_parser_go() {
			jQuery.post(ajaxurl, 'action=azlc_link_parse', azlc_parser_callback);
		}

		function azlc_parser_callback(data) {
			if(data==0) {
				clearInterval(azlc_parser);
			}
		}
		</script>
	<?php
	}


	public function already_parse( $post_ID ) {
		/* @var $wpdb WPDB */
		global $wpdb;
		$query = $wpdb->prepare( "SELECT id FROM " .  self::$database->post_status_table . " WHERE post_id = %d AND
		                                                                                               completed =
		1", $post_ID );
		$wpdb->get_results( $query );
		return $wpdb->num_rows > 0;
	}


	function extractRegion_iframe( $url ) {
		if ( stripos( $url, "&region=" ) === false ) {
			//todo: return default region
			return '';
		}
		$begin  = stripos( $url, "&region=" ) + 8;
		$end    = stripos( $url, "&", $begin );
		$length = $end - $begin;
		$region = substr( $url, $begin, $length );
		if ( strcmp( $region, 'US' ) === 0 ) {
			$region = "com";
		}

		return $region;
	}


	function extractAsin_iframe( $url ) {
		$begin = stripos( $url, "asins=" ) + 6;

		if ( ! isset( $begin ) ) {
			return '';
		} else {
			return substr( $url, $begin, 10 );
		}
	}



	function extractTag_iframe( $url ) {

		if ( stripos( $url, "&tracking_id=" ) === false ) {
			return '';
		}

		$begin  = stripos( $url, "&tracking_id" ) + 13;
		$end    = stripos( $url, "-20", $begin ) + 3;
		$length = $end - $begin;

		return substr( $url, $begin, $length );
	}
}


	$AZLC_link_parser = new AZLC_Link_Parser();

	register_activation_hook( __FILE__, array( 'AZLC_Link_Parser', 'activate' ) );

endif;
