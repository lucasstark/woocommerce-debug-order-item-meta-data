<?php

if(!class_exists('WP_List_Table')){
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class WC_Debug_Order_Item_Meta_Data_Table extends WP_List_Table {

	/**
	 * Constructor, we override the parent to pass our own arguments
	 * We usually focus on three parameters: singular and plural labels, as well as whether the class supports AJAX.
	 */
	function __construct() {
		parent::__construct( array(
			'singular' => 'wc_debug_order_item_meta_data_table',
			//Singular label
			'plural'   => 'wc_debug_order_item_meta_data_tables',
			//plural label, also this well be one of the table css class
			'ajax'     => false
			//We won't support Ajax for this table
		) );
	}

	/**
	 * Define the columns that are going to be used in the table
	 * @return array $columns, the array of columns to use with the table
	 */
	public function get_columns() {
		return [
			'col_log_id'          => __( 'ID' ),
			'col_log_order_id'    => __( 'Order' ),
			'col_log_error_count' => __( 'Errors' ),
			'col_log_when'        => __( 'Date Created' )
		];
	}

	public function prepare_items() {
		global $wpdb, $_wp_column_headers;
		$screen = get_current_screen();

		/* -- Register the Columns -- */
		//used by WordPress to build and fetch the _column_headers property
		$columns = $this->get_columns();
		$this->_column_headers =[$columns, [], []];

		/* -- Fetch the items -- */
		$this->items = $this->get_debug_entries();
	}

	/**
	 * Display the rows of records in the table
	 * @return string, echo the markup of the rows
	 */
	public function display_rows() {
		//Get the records registered in the prepare_items method
		$records = $this->items;
		list( $columns, $hidden ) = $this->get_column_info();

		//Loop for each record
		if ( ! empty( $records ) ) {
			foreach ( $records as $rec ) {
				$option_id = $rec->option_id;
				$log_entry = maybe_unserialize( $rec->option_value );
				//Open the line
				echo '<tr id="record_' . $option_id . '">';
				foreach ( $columns as $column_name => $column_display_name ) {

					//Style attributes for each col
					$class = "class='$column_name column-$column_name'";
					$style = "";
					if ( in_array( $column_name, $hidden ) ) {
						$style = ' style="display:none;"';
					}
					$attributes = $class . $style;

					//edit link
					$order_link = "/wp-admin/post.php?post={$log_entry['order_id']}&action=edit";
					$editlink = add_query_arg(['wc_debug_log_entry_id' => (int) $option_id]);

					//Display the cell
					switch ( $column_name ) {
						case "col_log_id":
							echo '<td ' . $attributes . '><a href="' . $order_link . '">Order ' . $log_entry['order_id'] . '</td>';
							break;
						case "col_log_order_id":
							echo '<td ' . $attributes . '><a href="' . $editlink . '">View Log</td>';
							break;
						case "col_log_error_count":
							echo '<td ' . $attributes . '>' .  count($log_entry['data'] ). '</td>';
							break;
						case "col_log_when":
							echo '<td ' . $attributes . '>' . date('Y-m-d H:i:s', intval($log_entry['timestamp'])). '</td>';
							break;
					}
				}

				//Close the line
				echo '</tr>';
			}
		}

	}

	public function get_debug_entries() {
		global $wpdb;
		$results = $wpdb->get_results( "SELECT * FROM wp_options WHERE option_name LIKE 'wc_debug_item_meta_%' ORDER BY option_id DESC" );

		return $results;
	}

}

