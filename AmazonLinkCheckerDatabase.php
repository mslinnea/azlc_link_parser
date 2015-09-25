<?php
/**
 * Created by PhpStorm.
 * User: Linnea
 * Date: 5/2/2015
 * Time: 7:15 PM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit();
} // Exit if accessed directly

if ( ! class_exists( 'AmazonLinkCheckerDatabase' ) ) :
	class AmazonLinkCheckerDatabase {


		public $link_instances_table;
		public $post_status_table;
		public $product_data_table;
		public $product_table;

		function __construct() {
			global $wpdb;
			$this->link_instances_table = $wpdb->prefix . 'azlkch_link_instances';
			$this->product_data_table   = $wpdb->prefix . 'azlkch_product_data';
			$this->product_table        = $wpdb->prefix . 'azlkch_products';
			$this->post_status_table    = $wpdb->prefix . 'azlkch_post_status';
		}


		/** Creates the tables
		 * or updates the table structure if table already exists
		 * based on: https://codex.wordpress.org/Creating_Tables_with_Plugins
		 *
		 */
		public function install() {
			/* @var $wpdb wpdb */
			global $wpdb;
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

			// install azlkch_link_instances table

			$charset_collate = $wpdb->get_charset_collate();
			// TODO: Add an index to the post_id and to the asin
			$sql = "CREATE TABLE " . $this->link_instances_table . " (
                id varchar(45) NOT NULL,
                post_id int(11) DEFAULT NULL,
                post_title varchar(1000) DEFAULT NULL,
                asin varchar(45) DEFAULT NULL,
                link_type varchar(45) DEFAULT NULL,
                link_code varchar(1000) DEFAULT NULL,
                link_text varchar(1000) DEFAULT NULL,
                url varchar(1000) DEFAULT NULL,
                affiliate_tag varchar(45) DEFAULT NULL,
                region varchar(45) DEFAULT NULL,
                time_updated timestamp NULL DEFAULT NULL,
                PRIMARY KEY  (id)
            ) $charset_collate;";

			dbDelta( $sql );

			// install azlkch_product_data table

			$sql = "CREATE TABLE " . $this->product_data_table . " (
        id int(11) NOT NULL AUTO_INCREMENT,
        asin varchar(45) NOT NULL,
        TotalNew int(6) DEFAULT NULL,
        TotalUsed int(6) DEFAULT NULL,
        TotalCollectible int(6) DEFAULT NULL,
        TotalRefurbished int(6) DEFAULT NULL,
        LowestUsedPrice  int(11) DEFAULT NULL,
        LowestCollectiblePrice  int(11) DEFAULT NULL,
        LowestRefurbishedPrice int(11) DEFAULT NULL,
        LowestNewPrice  int(11) DEFAULT NULL,
        error_code  varchar(100)  DEFAULT NULL,
        error_message  varchar(500)  DEFAULT NULL,
        time_of_retrieval timestamp NULL DEFAULT NULL,
        stock_status varchar(50) DEFAULT ' No Data',
        PRIMARY KEY  (id)
        ) $charset_collate;";

			dbDelta( $sql );

			// install azlkch_products table

			$sql = "CREATE TABLE " . $this->product_table . " (
        asin varchar(45) NOT NULL UNIQUE,
        title varchar(400) DEFAULT NULL,
        product_group varchar(200) DEFAULT NULL,
        region varchar(45) DEFAULT NULL,
        abstract tinyint(1) DEFAULT NULL,
        PRIMARY KEY  (asin)
      ) $charset_collate;";

			dbDelta( $sql );

			// install azlkch_post_status table
			// this table is used to keep track of which posts/pages
			// have already been parsed


			$sql = "CREATE TABLE " . $this->post_status_table . " (
		id int(11) NOT NULL AUTO_INCREMENT,
		post_id int(11) NOT NULL,
		completed tinyint DEFAULT NULL,
		time_updated timestamp NULL DEFAULT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";

			dbDelta( $sql );
		}


		public function deleteLinkInstances( $post_ID ) {
			/* @var WPDB $wpdb */
			global $wpdb;
			$post_ID_safe = filter_var( $post_ID, FILTER_SANITIZE_NUMBER_INT );
			$res          = $wpdb->delete( $this->link_instances_table, array( 'post_id' => $post_ID_safe ), array( '%d' ) );
			global $azlc_logger;
			$azlc_logger->write( "the delete link instances command returned: " . $res );
		}

	}

endif;

$azlc_database = new AmazonLinkCheckerDatabase();
