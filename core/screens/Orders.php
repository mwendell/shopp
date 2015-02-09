<?php
/**
 * Service.php
 *
 * Flow controller for order management interfaces
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, January 6, 2010
 * @package shopp
 * @subpackage shopp
 **/

defined( 'WPINC' ) || header( 'HTTP/1.1 403' ) & exit; // Prevent direct access

class ShoppAdminOrders extends ShoppAdminController {

	protected $ui = 'orders';

	protected function route () {
		if ( false !== strpos($this->request('page'), 'orders-new') )
			return 'ShoppScreenOrderEntry';
		elseif ( ! empty($this->request('id') ) )
			return 'ShoppScreenOrderManager';
		else return 'ShoppScreenOrders';
	}

	/**
	 * Retrieves the number of orders in each customized order status label
	 *
	 * @author Jonathan Davis
	 * @return void
	 **/
	public static function status_counts () {
		$table = ShoppDatabaseObject::tablename(ShoppPurchase::$table);
		$labels = shopp_setting('order_status');

		if (empty($labels)) return false;
		$status = array();

		$alltotal = sDB::query("SELECT count(*) AS total FROM $table",'auto','col','total');
		$r = sDB::query("SELECT status,COUNT(status) AS total FROM $table GROUP BY status ORDER BY status ASC", 'array', 'index', 'status');
		$all = array('' => Shopp::__('All Orders'));

		$labels = $all+$labels;

		foreach ($labels as $id => $label) {
			$_ = new StdClass();
			$_->label = $label;
			$_->id = $id;
			$_->total = 0;
			if ( isset($r[ $id ]) ) $_->total = (int)$r[$id]->total;
			if ('' === $id) $_->total = $alltotal;
			$status[$id] = $_;
		}

		return $status;
	}
}

/**
 * Service
 *
 * @package shopp
 * @since 1.1
 * @author Jonathan Davis
 **/
class ShoppScreenOrders extends ShoppScreenController {

	public $orders = array();
	public $ordercount = false;

	protected $ui = 'orders';
	protected $new = false;

	/**
	 * Service constructor
	 *
	 * @return void
	 * @author Jonathan Davis
	 **/
	// public function __construct () {
	// 	parent::__construct();
	//
	// 	$this->new = false !== strpos($this->id, 'orders-new');
	//
	// 	if ( isset($_GET['id']) || $this->new ) {
	//
	// 		wp_enqueue_script('postbox');
	// 		shopp_enqueue_script('colorbox');
	// 		shopp_enqueue_script('jquery-tmpl');
	// 		shopp_enqueue_script('orders');
	// 		shopp_localize_script( 'orders', '$om', array(
	// 			'co' => __('Cancel Order','Shopp'),
	// 			'mr' => __('Mark Refunded','Shopp'),
	// 			'pr' => __('Process Refund','Shopp'),
	// 			'dnc' => __('Do Not Cancel','Shopp'),
	// 			'ro' => __('Refund Order','Shopp'),
	// 			'cancel' => __('Cancel','Shopp'),
	// 			'rr' => __('Reason for refund','Shopp'),
	// 			'rc' => __('Reason for cancellation','Shopp'),
	// 			'mc' => __('Mark Cancelled','Shopp'),
	// 			'stg' => __('Send to gateway','Shopp')
	// 		));
	// 		shopp_enqueue_script('address');
	// 		shopp_enqueue_script('selectize');
	// 		shopp_custom_script( 'address', 'var regions = '.json_encode(Lookup::country_zones()).';');
	//
	// 		add_action('load-' . $this->id, array($this, 'workflow'));
	// 		$layout = $this->new ? array($this, 'newlayout') : array($this, 'layout');
	//
	// 		add_action('load-' . $this->id, $layout);
	// 		do_action('shopp_order_management_scripts');
	//
	// 	} else {
	// 		add_action('load-' . $this->id, array($this, 'loader'));
	// 		add_action('admin_print_scripts', array($this, 'columns'));
	// 	}
	// 	do_action('shopp_order_admin_scripts');
	// }

	public function __construct ( $ui ) {
		parent::__construct($ui);
		add_action('load-' . $this->id, array($this, 'loader'));
	}
	/**
	 * admin
	 *
	 * @return void
	 * @author Jonathan Davis
	 **/
	public function route () {

		if ( ! empty($_GET['id']) || $this->new) $this->manager();
		else $this->orders();
	}

	public function workflow () {
		$id = (int) $_GET['id'];
		if ( $id > 0 ) {
			ShoppPurchase( new ShoppPurchase($id) );
			ShoppPurchase()->load_purchased();
			ShoppPurchase()->load_events();
		} else ShoppPurchase( new ShoppPurchase() );
	}

	/**
	 * Handles orders list loading
	 *
	 * @author Jonathan Davis
	 * @since 1.2.1
	 *
	 * @return void
	 **/
	public function loader () {
		if ( ! current_user_can('shopp_orders') ) return;

		$defaults = array(
			'page' => false,
			'deleting' => false,
			'selected' => false,
			'update' => false,
			'newstatus' => false,
			'pagenum' => 1,
			'paged' => 1,
			'per_page' => 20,
			'start' => '',
			'end' => '',
			'status' => false,
			's' => '',
			'range' => '',
			'startdate' => '',
			'enddate' => '',
		);

		$args = array_merge($defaults, $_GET);
		extract($args, EXTR_SKIP);

		$url = $this->url($_GET);

		if ( $page == "shopp-orders"
						&& !empty($deleting)
						&& !empty($selected)
						&& is_array($selected)
						&& current_user_can('shopp_delete_orders')) {
			foreach($selected as $selection) {
				$Purchase = new ShoppPurchase($selection);
				$Purchase->load_purchased();
				foreach ($Purchase->purchased as $purchased) {
					$Purchased = new ShoppPurchased($purchased->id);
					$Purchased->delete();
				}
				$Purchase->delete();
			}
			if (count($selected) == 1) $this->notice(__('Order deleted.','Shopp'));
			else $this->notice(sprintf(__('%d orders deleted.','Shopp'),count($selected)));
		}

		$statusLabels = shopp_setting('order_status');
		if (empty($statusLabels)) $statusLabels = array('');
		$txnstatus_labels = Lookup::txnstatus_labels();

		if ($update == "order"
						&& !empty($selected)
						&& is_array($selected)) {
			foreach($selected as $selection) {
				$Purchase = new ShoppPurchase($selection);
				$Purchase->status = $newstatus;
				$Purchase->save();
			}
			if (count($selected) == 1) $this->notice(__('Order status updated.','Shopp'));
			else $this->notice(sprintf(__('%d orders updated.','Shopp'),count($selected)));
		}

		$Purchase = new ShoppPurchase();

		$offset = get_option( 'gmt_offset' ) * 3600;

		if (!empty($start)) {
			$startdate = $start;
			list($month,$day,$year) = explode("/",$startdate);
			$starts = mktime(0,0,0,$month,$day,$year);
		}
		if (!empty($end)) {
			$enddate = $end;
			list($month,$day,$year) = explode("/",$enddate);
			$ends = mktime(23,59,59,$month,$day,$year);
		}

		$pagenum = absint( $paged );
		$start = ($per_page * ($pagenum-1));

		$where = array();
		$joins = array();
		if (!empty($status) || $status === '0') $where[] = "status='".sDB::escape($status)."'";
		if (!empty($s)) {
			$s = stripslashes($s);
			$search = array();
			if (preg_match_all('/(\w+?)\:(?="(.+?)"|(.+?)\b)/',$s,$props,PREG_SET_ORDER) > 0) {
				foreach ($props as $query) {
					$keyword = sDB::escape( ! empty($query[2]) ? $query[2] : $query[3] );
					switch(strtolower($query[1])) {
						case "txn": 		$search[] = "txnid='$keyword'"; break;
						case "company":		$search[] = "company LIKE '%$keyword%'"; break;
						case "gateway":		$search[] = "gateway LIKE '%$keyword%'"; break;
						case "cardtype":	$search[] = "cardtype LIKE '%$keyword%'"; break;
						case "address": 	$search[] = "(address LIKE '%$keyword%' OR xaddress='%$keyword%')"; break;
						case "city": 		$search[] = "city LIKE '%$keyword%'"; break;
						case "province":
						case "state": 		$search[] = "state='$keyword'"; break;
						case "zip":
						case "zipcode":
						case "postcode":	$search[] = "postcode='$keyword'"; break;
						case "country": 	$search[] = "country='$keyword'"; break;
						case "promo":
						case "discount":
											$meta_table = ShoppDatabaseObject::tablename(ShoppMetaObject::$table);
											$joins[$meta_table] = "INNER JOIN $meta_table AS m ON m.parent = o.id AND context='purchase'";
											$search[] = "m.value LIKE '%$keyword%'"; break;
						case "product":
											$purchased = ShoppDatabaseObject::tablename(Purchased::$table);
											$joins[$purchased] = "INNER JOIN $purchased AS p ON p.purchase = o.id";
											$search[] = "p.name LIKE '%$keyword%' OR p.optionlabel LIKE '%$keyword%' OR p.sku LIKE '%$keyword%'"; break;
					}
				}
				if (empty($search)) $search[] = "(id='$s' OR CONCAT(firstname,' ',lastname) LIKE '%$s%')";
				$where[] = "(".join(' OR ',$search).")";
			} elseif (strpos($s,'@') !== false) {
				 $where[] = "email='".sDB::escape($s)."'";
			} else $where[] = "(id='$s' OR CONCAT(firstname,' ',lastname) LIKE '%".sDB::escape($s)."%')";
		}
		if (!empty($starts) && !empty($ends)) $where[] = "created BETWEEN '".sDB::mkdatetime($starts)."' AND '".sDB::mkdatetime($ends)."'";

		if (!empty($customer)) $where[] = "customer=".intval($customer);
		$where = !empty($where) ? "WHERE ".join(' AND ',$where) : '';
		$joins = join(' ', $joins);

		$countquery = "SELECT count(*) as total,SUM(IF(txnstatus IN ('authed','captured'),total,NULL)) AS sales,AVG(IF(txnstatus IN ('authed','captured'),total,NULL)) AS avgsale FROM $Purchase->_table AS o $joins $where ORDER BY o.created DESC LIMIT 1";
		$this->ordercount = sDB::query($countquery,'object');

		$query = "SELECT o.* FROM $Purchase->_table AS o $joins $where ORDER BY created DESC LIMIT $start,$per_page";
		$this->orders = sDB::query($query,'array','index','id');

		$num_pages = ceil($this->ordercount->total / $per_page);
		if ($paged > 1 && $paged > $num_pages) Shopp::redirect( add_query_arg('paged', null, $url) );

	}

	public function assets () {
		shopp_enqueue_script('calendar');
		shopp_enqueue_script('daterange');
		do_action('shopp_order_admin_scripts');
	}

	/**
	 * Registers the column headers for the orders list interface
	 *
	 * Uses the WordPress 2.7 function register_column_headers to provide
	 * customizable columns that can be toggled to show or hide
	 *
	 * @author Jonathan Davis
	 * @return void
	 **/
	public function layout () {
		register_column_headers($this->id, array(
			'cb'=>'<input type="checkbox" />',
			'order'=>__('Order','Shopp'),
			'name'=>__('Name','Shopp'),
			'destination'=>__('Destination','Shopp'),
			'txn'=>__('Transaction','Shopp'),
			'date'=>__('Date','Shopp'),
			'total'=>__('Total','Shopp'))
		);
	}

	/**
	 * Interface processor for the orders list interface
	 *
	 * @author Jonathan Davis
	 *
	 * @return void
	 **/
	public function screen () {
		if ( ! current_user_can('shopp_orders') )
			wp_die(__('You do not have sufficient permissions to access this page.','Shopp'));

		$defaults = array(
			'page' => false,
			'update' => false,
			'newstatus' => false,
			'paged' => 1,
			'per_page' => 20,
			'status' => false,
			's' => '',
			'range' => '',
			'startdate' => '',
			'enddate' => ''
		);

		$args = array_merge($defaults,$_GET);
		extract($args, EXTR_SKIP);

		$s = stripslashes($s);

		$statusLabels = shopp_setting('order_status');
		if (empty($statusLabels)) $statusLabels = array('');
		$txnstatus_labels = Lookup::txnstatus_labels();

		$Purchase = new ShoppPurchase();

		$Orders = $this->orders;
		$ordercount = $this->ordercount;
		$num_pages = ceil($ordercount->total / $per_page);

		$ListTable = ShoppUI::table_set_pagination ($this->id, $ordercount->total, $num_pages, $per_page );

		$ranges = array(
			'all' => __('Show All Orders','Shopp'),
			'today' => __('Today','Shopp'),
			'week' => __('This Week','Shopp'),
			'month' => __('This Month','Shopp'),
			'quarter' => __('This Quarter','Shopp'),
			'year' => __('This Year','Shopp'),
			'yesterday' => __('Yesterday','Shopp'),
			'lastweek' => __('Last Week','Shopp'),
			'last30' => __('Last 30 Days','Shopp'),
			'last90' => __('Last 3 Months','Shopp'),
			'lastmonth' => __('Last Month','Shopp'),
			'lastquarter' => __('Last Quarter','Shopp'),
			'lastyear' => __('Last Year','Shopp'),
			'lastexport' => __('Last Export','Shopp'),
			'custom' => __('Custom Dates','Shopp')
			);

		$exports = array(
			'tab' => __('Tab-separated.txt','Shopp'),
			'csv' => __('Comma-separated.csv','Shopp'),
			'xls' => __('Microsoft&reg; Excel.xls','Shopp'),
			'iif' => __('Intuit&reg; QuickBooks.iif','Shopp')
			);

		$formatPref = shopp_setting('purchaselog_format');
		if (!$formatPref) $formatPref = 'tab';

		$exportcolumns = array_merge(ShoppPurchase::exportcolumns(),ShoppPurchased::exportcolumns());
		$selected = shopp_setting('purchaselog_columns');
		if ( empty($selected) ) $selected = array_keys($exportcolumns);

		$Shopp = Shopp::object();
		$Gateways = array_merge($Shopp->Gateways->modules, array('ShoppFreeOrder' => $Shopp->Gateways->freeorder));

		include $this->ui('orders.php');
	}

	private function retotal ( ShoppPurchase $Purchase ) {
		$Cart = new ShoppCart();

		$taxcountry = $Purchase->country;
		$taxstate = $Purchase->state;
		if ( ! empty($Purchase->shipcountry) && ! empty($Purchase->shipstate) ) {
			$taxcountry = $Purchase->shipcountry;
			$taxstate = $Purchase->shipstate;
		}
		ShoppOrder()->Tax->location($taxcountry, $taxstate);

		foreach ( $Purchase->purchased as $index => &$Purchased )
			$Cart->additem($Purchased->quantity, new ShoppCartItem($Purchased));

		$Cart->Totals->register( new OrderAmountShipping( array('id' => 'cart', 'amount' => $Purchase->freight ) ) );

		$Purchase->total = $Cart->total();
		$Purchase->subtotal = $Cart->total('order');
		$Purchase->discount = $Cart->total('discount');
		$Purchase->tax = $Cart->total('tax');
		$Purchase->freight = $Cart->total('shipping');
	}

} // class ShoppScreenOrders

class ShoppScreenOrderManager extends ShoppScreenController {

	public function addnote ($order, $message, $sent = false) {
		$user = wp_get_current_user();
		$Note = new ShoppMetaObject();
		$Note->parent = $order;
		$Note->context = 'purchase';
		$Note->type = 'order_note';
		$Note->name = 'note';
		$Note->value = new stdClass();
		$Note->value->author = $user->ID;
		$Note->value->message = $message;
		$Note->value->sent = $sent;
		$Note->save();
	}

	public function load () {
		$id = (int) $_GET['id'];
		if ( $id > 0 ) {
			ShoppPurchase( new ShoppPurchase($id) );
			ShoppPurchase()->load_purchased();
			ShoppPurchase()->load_events();
		} else ShoppPurchase( new ShoppPurchase() );
	}

	public function assets () {

		wp_enqueue_script('postbox');

		shopp_enqueue_script('colorbox');
		shopp_enqueue_script('jquery-tmpl');
		shopp_enqueue_script('selectize');

		shopp_enqueue_script('orders');
		shopp_localize_script( 'orders', '$om', array(
			'co' => __('Cancel Order','Shopp'),
			'mr' => __('Mark Refunded','Shopp'),
			'pr' => __('Process Refund','Shopp'),
			'dnc' => __('Do Not Cancel','Shopp'),
			'ro' => __('Refund Order','Shopp'),
			'cancel' => __('Cancel','Shopp'),
			'rr' => __('Reason for refund','Shopp'),
			'rc' => __('Reason for cancellation','Shopp'),
			'mc' => __('Mark Cancelled','Shopp'),
			'stg' => __('Send to gateway','Shopp')
		));


		shopp_enqueue_script('address');
		shopp_custom_script( 'address', 'var regions = ' . json_encode(ShoppLookup::country_zones()) . ';');

		do_action('shopp_order_management_scripts');
	}

	/**
	 * Provides overall layout for the order manager interface
	 *
	 * Makes use of WordPress postboxes to generate panels (box) content
	 * containers that are customizable with drag & drop, collapsable, and
	 * can be toggled to be hidden or visible in the interface.
	 *
	 * @author Jonathan Davis
	 * @return
	 **/
	public function layout () {

		$Purchase = ShoppPurchase();

		ShoppUI::register_column_headers($this->id, apply_filters('shopp_order_manager_columns', array(
			'items' => Shopp::__('Items'),
			'qty'   => Shopp::__('Quantity'),
			'price' => Shopp::__('Price'),
			'total' => Shopp::__('Total')
		)));

		$references = array('Purchase' => $Purchase);

		new ShoppAdminOrderContactBox($this->id, 'side', 'core', $references);
		new ShoppAdminOrderBillingAddressBox($this->id, 'side', 'core', $references);

		if ( ! empty($Purchase->shipaddress) )
			new ShoppAdminOrderShippingAddressBox($this->id, 'side', 'core', $references);

		new ShoppAdminOrderManageBox($this->id,'normal','core',$references);

		if ( isset($Purchase->data) && '' != join('', (array)$Purchase->data) || apply_filters('shopp_orderui_show_orderdata', false) )
			new ShoppAdminOrderDataBox($this->id, 'normal', 'core', $references);

		if ( count($Purchase->events) > 0 )
			new ShoppAdminOrderHistoryBox($this->id, 'normal', 'core', $references);

		new ShoppAdminOrderNotesBox($this->id, 'normal', 'core', $references);

		do_action('shopp_order_manager_layout');

	}

	/**
	 * Interface processor for the order manager
	 *
	 * @author Jonathan Davis
	 * @return void
	 **/
	public function screen () {

		if ( ! current_user_can('shopp_orders') )
			wp_die(__('You do not have sufficient permissions to access this page.','Shopp'));

		$Purchase = ShoppPurchase();
		$Purchase->Customer = new ShoppCustomer($Purchase->customer);
		$Gateway = $Purchase->gateway();

		if ( ! empty($_POST['send-note']) ){
			$user = wp_get_current_user();
			shopp_add_order_event($Purchase->id,'note',array(
				'note' => stripslashes($_POST['note']),
				'user' => $user->ID
			));

			$Purchase->load_events();
		}

		if ( isset($_POST['submit-shipments']) && isset($_POST['shipment']) && !empty($_POST['shipment']) ) {
			$shipments = $_POST['shipment'];
			foreach ((array)$shipments as $shipment) {
				shopp_add_order_event($Purchase->id,'shipped',array(
					'tracking' => $shipment['tracking'],
					'carrier' => $shipment['carrier']
				));
			}
			$updated = __('Shipping notice sent.','Shopp');

			// Save shipping carrier default preference for the user
			$userid = get_current_user_id();
			$setting = 'shopp_shipping_carrier';
			if ( ! get_user_meta($userid, $setting, true) )
				add_user_meta($userid, $setting, $shipment['carrier']);
			else update_user_meta($userid, $setting, $shipment['carrier']);

			unset($_POST['ship-notice']);
			$Purchase->load_events();
		}

		if (isset($_POST['order-action']) && 'refund' == $_POST['order-action']) {
			if ( ! current_user_can('shopp_refund') )
				wp_die(__('You do not have sufficient permissions to carry out this action.','Shopp'));

			$user = wp_get_current_user();
			$reason = (int)$_POST['reason'];
			$amount = Shopp::floatval($_POST['amount']);

			if (!empty($_POST['message'])) {
				$message = $_POST['message'];
				$Purchase->message['note'] = $message;
			}

			if (!Shopp::str_true($_POST['send'])) { // Force the order status
				shopp_add_order_event($Purchase->id,'notice',array(
					'user' => $user->ID,
					'kind' => 'refunded',
					'notice' => __('Marked Refunded','Shopp')
				));
				shopp_add_order_event($Purchase->id,'refunded',array(
					'txnid' => $Purchase->txnid,
					'gateway' => $Gateway->module,
					'amount' => $amount
				));
				shopp_add_order_event($Purchase->id,'voided',array(
					'txnorigin' => $Purchase->txnid,	// Original transaction ID (txnid of original Purchase record)
					'txnid' => time(),					// Transaction ID for the VOID event
					'gateway' => $Gateway->module		// Gateway handler name (module name from @subpackage)
				));
			} else {
				shopp_add_order_event($Purchase->id,'refund',array(
					'txnid' => $Purchase->txnid,
					'gateway' => $Gateway->module,
					'amount' => $amount,
					'reason' => $reason,
					'user' => $user->ID
				));
			}

			if (!empty($_POST['message']))
				$this->addnote($Purchase->id,$_POST['message']);

			$Purchase->load_events();
		}

		if (isset($_POST['order-action']) && 'cancel' == $_POST['order-action']) {
			if ( ! current_user_can('shopp_void') )
				wp_die(__('You do not have sufficient permissions to carry out this action.','Shopp'));

			// unset($_POST['refund-order']);
			$user = wp_get_current_user();
			$reason = (int)$_POST['reason'];

			$message = '';
			if (!empty($_POST['message'])) {
				$message = $_POST['message'];
				$Purchase->message['note'] = $message;
			} else $message = 0;


			if (!Shopp::str_true($_POST['send'])) { // Force the order status
				shopp_add_order_event($Purchase->id,'notice',array(
					'user' => $user->ID,
					'kind' => 'cancelled',
					'notice' => __('Marked Cancelled','Shopp')
				));
				shopp_add_order_event($Purchase->id,'voided',array(
					'txnorigin' => $Purchase->txnid,	// Original transaction ID (txnid of original Purchase record)
					'txnid' => time(),			// Transaction ID for the VOID event
					'gateway' => $Gateway->module		// Gateway handler name (module name from @subpackage)
				));
			} else {
				shopp_add_order_event($Purchase->id,'void',array(
					'txnid' => $Purchase->txnid,
					'gateway' => $Gateway->module,
					'reason' => $reason,
					'user' => $user->ID,
					'note' => $message
				));
			}

			if ( ! empty($_POST['message']) )
				$this->addnote($Purchase->id,$_POST['message']);

			$Purchase->load_events();
		}

		if ( isset($_POST['billing']) && is_array($_POST['billing']) ) {

			$Purchase->updates($_POST['billing']);
			$Purchase->save();

		}

		if ( isset($_POST['shipping']) && is_array($_POST['shipping']) ) {

			$shipping = array();
			foreach( $_POST['shipping'] as $name => $value )
				$shipping[ "ship$name" ] = $value;

			$Purchase->updates($shipping);
			$Purchase->shipname = $shipping['shipfirstname'] . ' ' . $shipping['shiplastname'];

			$Purchase->save();
		}


		if ( isset($_POST['order-action']) && 'update-customer' == $_POST['order-action'] && ! empty($_POST['customer'])) {
			$Purchase->updates($_POST['customer']);
			$Purchase->save();
		}

		if ( isset($_POST['cancel-edit-customer']) ){
			unset($_POST['order-action'],$_POST['edit-customer'],$_POST['select-customer']);
		}

		// Create a new customer
		if ( isset($_POST['order-action']) && 'new-customer' == $_POST['order-action'] && ! empty($_POST['customer']) && ! isset($_POST['cancel-edit-customer'])) {
			$Customer = new ShoppCustomer();
			$Customer->updates($_POST['customer']);
			$Customer->password = wp_generate_password(12,true);
			if ( 'wordpress' == shopp_setting('account_system') ) $Customer->create_wpuser();
			else unset($_POST['loginname']);
			$Customer->save();
			if ( (int)$Customer->id > 0 ) {
				$Purchase->customer = $Customer->id;
				$Purchase->copydata($Customer);
				$Purchase->save();

				// New billing address, create record for new customer
				if ( isset($_POST['billing']) && is_array($_POST['billing']) && empty($_POST['billing']['id']) ) {
					$Billing = new BillingAddress($_POST['billing']);
					$Billing->customer = $Customer->id;
					$Billing->save();
				}

				// New shipping address, create record for new customer
				if ( isset($_POST['shipping']) && is_array($_POST['shipping']) && empty($_POST['shipping']['id']) ) {
					$Shipping = new ShippingAddress($_POST['shipping']);
					$Shipping->customer = $Customer->id;
					$Shipping->save();
				}

			} else $this->notice(Shopp::__('An unknown error occured. The customer could not be created.'), 'error');
		}

		if ( isset($_GET['order-action']) && 'change-customer' == $_GET['order-action'] && ! empty($_GET['customerid'])) {
			$Customer = new ShoppCustomer((int)$_GET['customerid']);
			if ( (int)$Customer->id > 0) {
				$Purchase->copydata($Customer);
				$Purchase->customer = $Customer->id;
				$Purchase->save();
			} else $this->notice(Shopp::__('The selected customer was not found.'), 'error');
		}

		if ( isset($_POST['save-item']) && isset($_POST['lineid']) ) {

			if ( isset($_POST['lineid']) && '' == $_POST['lineid'] ) {
				$lineid = 'new';
			} else $lineid = (int)$_POST['lineid'];

			$name = $_POST['itemname'];
			if ( ! empty( $_POST['product']) ) {
				list($productid, $priceid) = explode('-', $_POST['product']);
				$Product = new ShoppProduct($productid);
				$Price = new ShoppPrice($priceid);
				$name = $Product->name;
				if ( Shopp::__('Price & Delivery') != $Price->label )
					$name .= ": $Price->label";
			}

			// Create a cart representation of the order to recalculate order totals
			$Cart = new ShoppCart();

			$taxcountry = $Purchase->country;
			$taxstate = $Purchase->state;
			if ( ! empty($Purchase->shipcountry) && ! empty($Purchase->shipstate) ) {
				$taxcountry = $Purchase->shipcountry;
				$taxstate = $Purchase->shipstate;
			}
			ShoppOrder()->Tax->location($taxcountry, $taxstate);

			if ( 'new' == $lineid ) {
				$NewLineItem = new ShoppPurchased();
				$NewLineItem->purchase = $Purchase->id;
				$Purchase->purchased[] = $NewLineItem;
			}

			foreach ( $Purchase->purchased as &$Purchased ) {
				$CartItem = new ShoppCartItem($Purchased);

				if ( $Purchased->id == $lineid || ('new' == $lineid && empty($Purchased->id) ) ) {

					if ( ! empty( $_POST['product']) ) {
						list($CartItem->product, $CartItem->priceline) = explode('-', $_POST['product']);
					} elseif ( ! empty($_POST['id']) ) {
						list($CartItem->product, $CartItem->priceline) = explode('-', $_POST['id']);
					}

					$CartItem->name = $name;
					$CartItem->unitprice = Shopp::floatval($_POST['unitprice']);
					$Cart->additem((int)$_POST['quantity'], $CartItem);
					$CartItem = $Cart->get($CartItem->fingerprint());

					$Purchased->name = $CartItem->name;
					$Purchased->product = $CartItem->product;
					$Purchased->price = $CartItem->priceline;
					$Purchased->quantity = $CartItem->quantity;
					$Purchased->unitprice = $CartItem->unitprice;
					$Purchased->total = $CartItem->total;
					$Purchased->save();

				} else $Cart->additem($CartItem->quantity, $CartItem);

			}

			$Cart->Totals->register( new OrderAmountShipping( array('id' => 'cart', 'amount' => $Purchase->freight ) ) );

			$Purchase->total = $Cart->total();
			$Purchase->subtotal = $Cart->total('order');
			$Purchase->discount = $Cart->total('discount');
			$Purchase->tax = $Cart->total('tax');
			$Purchase->freight = $Cart->total('shipping');
			$Purchase->save();
			$Purchase->load_purchased();

		}

		if ( ! empty($_POST['save-totals']) ) {

			$totals = array();
			if ( ! empty($_POST['totals']) )
				$totals = $_POST['totals'];

			$objects = array(
				'tax' => 'OrderAmountTax',
				'shipping' => 'OrderAmountShipping',
				'discount' => 'OrderAmountDiscount'
			);

			$methods = array(
				'fee' => 'fees',
				'tax' => 'taxes',
				'shipping' => 'shipfees',
				'discount' => 'discounts'
			);

			$total = 0;
			foreach ( $totals as $property => $fields ) {
				if ( empty($fields) ) continue;

				if ( count($fields) > 1 ) {
					if ( isset($fields['labels']) ) {
						$labels = $fields['labels'];
						unset($fields['labels']);
						if ( count($fields) > count($labels) )
							$totalfield = array_pop($fields);

						$fields = array_combine($labels, $fields);
					}

					$fields = array_map(array('Shopp', 'floatval'), $fields);

					$entries = array();
					$OrderAmountObject = isset($objects[ $property ]) ? $objects[ $property ] : 'OrderAmountFee';
					foreach ( $fields as $label => $amount )
						$entries[] = new $OrderAmountObject(array('id' => count($entries) + 1, 'label' => $label, 'amount' => $amount));

					$savetotal = isset($methods[ $property ]) ? $methods[ $property ] : $fees;
					$Purchase->$savetotal($entries);

					$sum = array_sum($fields);
					if ( $sum > 0 )
						$Purchase->$property = $sum;

				} else $Purchase->$property = Shopp::floatval($fields[0]);

				$total += ('discount' == $property ? $Purchase->$property * -1 : $Purchase->$property );

			}

			$Purchase->total = $Purchase->subtotal + $total;
			$Purchase->save();
		}

		if ( ! empty($_GET['rmvline']) ) {
			$lineid = (int)$_GET['rmvline'];
			if ( isset($Purchase->purchased[ $lineid ]) ) {
				$Purchase->purchased[ $lineid ]->delete();
				unset($Purchase->purchased[ $lineid ]);
			}

			$Cart = new ShoppCart();

			$taxcountry = $Purchase->country;
			$taxstate = $Purchase->state;
			if ( ! empty($Purchase->shipcountry) && ! empty($Purchase->shipstate) ) {
				$taxcountry = $Purchase->shipcountry;
				$taxstate = $Purchase->shipstate;
			}
			ShoppOrder()->Tax->location($taxcountry, $taxstate);

			foreach ( $Purchase->purchased as &$Purchased )
				$Cart->additem($Purchased->quantity, new ShoppCartItem($Purchased));

			$Cart->Totals->register( new OrderAmountShipping( array('id' => 'cart', 'amount' => $Purchase->freight ) ) );

			$Purchase->total = $Cart->total();
			$Purchase->subtotal = $Cart->total('order');
			$Purchase->discount = $Cart->total('discount');
			$Purchase->tax = $Cart->total('tax');
			$Purchase->freight = $Cart->total('shipping');
			$Purchase->save();

			$Purchase->load_purchased();
		}


		if (isset($_POST['charge']) && $Gateway && $Gateway->captures) {
			if ( ! current_user_can('shopp_capture') )
				wp_die(__('You do not have sufficient permissions to carry out this action.','Shopp'));

			$user = wp_get_current_user();

			shopp_add_order_event($Purchase->id,'capture',array(
				'txnid' => $Purchase->txnid,
				'gateway' => $Purchase->gateway,
				'amount' => $Purchase->capturable(),
				'user' => $user->ID
			));

			$Purchase->load_events();
		}

		$targets = shopp_setting('target_markets');
		$default = array('' => '&nbsp;');
		$Purchase->_countries = array_merge($default, ShoppLookup::countries());

		$regions = Lookup::country_zones();
		$Purchase->_billing_states = array_merge($default, (array)$regions[ $Purchase->country ]);
		$Purchase->_shipping_states = array_merge($default, (array)$regions[ $Purchase->shipcountry ]);

		// Setup shipping carriers menu and JS data
		$carriers_menu = $carriers_json = array();
		$shipping_carriers = (array) shopp_setting('shipping_carriers'); // The store-preferred shipping carriers
		$shipcarriers = Lookup::shipcarriers(); // The full list of available shipping carriers
		$notrack = Shopp::__('No Tracking'); // No tracking label
		$default = get_user_meta(get_current_user_id(), 'shopp_shipping_carrier', true);

		if ( isset($shipcarriers[ $default ]) ) {
			$carriers_menu[ $default ] = $shipcarriers[ $default ]->name;
			$carriers_json[ $default ] = array($shipcarriers[ $default ]->name, $shipcarriers[ $default ]->trackpattern);
		} else {
			$carriers_menu['NOTRACKING'] = $notrack;
			$carriers_json['NOTRACKING'] = array($notrack, false);
		}

			$serviceareas = array('*', ShoppBaseLocale()->country());
			foreach ( $shipcarriers as $code => $carrier ) {
			if ( $code == $default ) continue;
			if ( ! empty($shipping_carriers) && ! in_array($code, $shipping_carriers) ) continue;
				if ( ! in_array($carrier->areas, $serviceareas) ) continue;
				$carriers_menu[ $code ] = $carrier->name;
				$carriers_json[ $code ] = array($carrier->name, $carrier->trackpattern);
			}

		if ( isset($shipcarriers[ $default ]) ) {
			$carriers_menu['NOTRACKING'] = $notrack;
			$carriers_json['NOTRACKING'] = array($notrack, false);
		}

		if ( empty($statusLabels) ) $statusLabels = array('');

		$Purchase->taxes();
		$Purchase->discounts();

		$columns = get_column_headers($this->id);
		$hidden = get_hidden_columns($this->id);

		include $this->ui('order.php');
	}

} // class ShoppScreenOrderManager

class ShoppScreenOrderEntry extends ShoppScreenOrderManager {

	public function load () {
		return ShoppPurchase(new ShoppPurchase());
	}

	public function layout () {

		$Purchase = ShoppPurchase();

		ShoppUI::register_column_headers($this->id, apply_filters('shopp_order_manager_columns',array(
			'items' => __('Items','Shopp'),
			'qty' => __('Quantity','Shopp'),
			'price' => __('Price','Shopp'),
			'total' => __('Total','Shopp')
		)));

		new ShoppAdminOrderContactBox(
			$this->id,
			'topside',
			'core',
			array('Purchase' => $Purchase)
		);

		new ShoppAdminOrderBillingAddressBox(
			$this->id,
			'topic',
			'core',
			array('Purchase' => $Purchase)
		);


		new ShoppAdminOrderShippingAddressBox(
			$this->id,
			'topsider',
			'core',
			array('Purchase' => $Purchase)
		);

		new ShoppAdminOrderManageBox(
			$this->id,
			'normal',
			'core',
			array('Purchase' => $Purchase, 'Gateway' => $Purchase->gateway())
		);

		if ( isset($Purchase->data) && '' != join('', (array)$Purchase->data) || apply_filters('shopp_orderui_show_orderdata', false) )
			new ShoppAdminOrderDataBox(
				$this->id,
				'normal',
				'core',
				array('Purchase' => $Purchase)
			);

		if ( count($Purchase->events) > 0 )
			new ShoppAdminOrderHistoryBox(
				$this->id,
				'normal',
				'core',
				array('Purchase' => $Purchase)
			);

		new ShoppAdminOrderNotesBox(
			$this->id,
			'normal',
			'core',
			array('Purchase' => $Purchase)
		);

		do_action('shopp_order_new_layout');
	}

	function screen () {
		if ( ! current_user_can('shopp_orders') )
			wp_die(__('You do not have sufficient permissions to access this page.','Shopp'));

		$Purchase = ShoppPurchase();
		$Purchase->Customer = new ShoppCustomer($Purchase->customer);
		$Gateway = $Purchase->gateway();

		if ( ! empty($_POST['send-note']) ){
			$user = wp_get_current_user();
			shopp_add_order_event($Purchase->id,'note',array(
				'note' => stripslashes($_POST['note']),
				'user' => $user->ID
			));

			$Purchase->load_events();
		}

		if ( isset($_POST['submit-shipments']) && isset($_POST['shipment']) && !empty($_POST['shipment']) ) {
			$shipments = $_POST['shipment'];
			foreach ((array)$shipments as $shipment) {
				shopp_add_order_event($Purchase->id,'shipped',array(
					'tracking' => $shipment['tracking'],
					'carrier' => $shipment['carrier']
				));
			}
			$updated = __('Shipping notice sent.','Shopp');

			// Save shipping carrier default preference for the user
			$userid = get_current_user_id();
			$setting = 'shopp_shipping_carrier';
			if ( ! get_user_meta($userid, $setting, true) )
				add_user_meta($userid, $setting, $shipment['carrier']);
			else update_user_meta($userid, $setting, $shipment['carrier']);

			unset($_POST['ship-notice']);
			$Purchase->load_events();
		}

		if (isset($_POST['order-action']) && 'refund' == $_POST['order-action']) {
			if ( ! current_user_can('shopp_refund') )
				wp_die(__('You do not have sufficient permissions to carry out this action.','Shopp'));

			$user = wp_get_current_user();
			$reason = (int)$_POST['reason'];
			$amount = Shopp::floatval($_POST['amount']);

			if (!empty($_POST['message'])) {
				$message = $_POST['message'];
				$Purchase->message['note'] = $message;
			}

			if (!Shopp::str_true($_POST['send'])) { // Force the order status
				shopp_add_order_event($Purchase->id,'notice',array(
					'user' => $user->ID,
					'kind' => 'refunded',
					'notice' => __('Marked Refunded','Shopp')
				));
				shopp_add_order_event($Purchase->id,'refunded',array(
					'txnid' => $Purchase->txnid,
					'gateway' => $Gateway->module,
					'amount' => $amount
				));
				shopp_add_order_event($Purchase->id,'voided',array(
					'txnorigin' => $Purchase->txnid,	// Original transaction ID (txnid of original Purchase record)
					'txnid' => time(),					// Transaction ID for the VOID event
					'gateway' => $Gateway->module		// Gateway handler name (module name from @subpackage)
				));
			} else {
				shopp_add_order_event($Purchase->id,'refund',array(
					'txnid' => $Purchase->txnid,
					'gateway' => $Gateway->module,
					'amount' => $amount,
					'reason' => $reason,
					'user' => $user->ID
				));
			}

			if (!empty($_POST['message']))
				$this->addnote($Purchase->id,$_POST['message']);

			$Purchase->load_events();
		}

		if (isset($_POST['order-action']) && 'cancel' == $_POST['order-action']) {
			if ( ! current_user_can('shopp_void') )
				wp_die(__('You do not have sufficient permissions to carry out this action.','Shopp'));

			// unset($_POST['refund-order']);
			$user = wp_get_current_user();
			$reason = (int)$_POST['reason'];

			$message = '';
			if (!empty($_POST['message'])) {
				$message = $_POST['message'];
				$Purchase->message['note'] = $message;
			} else $message = 0;


			if (!Shopp::str_true($_POST['send'])) { // Force the order status
				shopp_add_order_event($Purchase->id,'notice',array(
					'user' => $user->ID,
					'kind' => 'cancelled',
					'notice' => __('Marked Cancelled','Shopp')
				));
				shopp_add_order_event($Purchase->id,'voided',array(
					'txnorigin' => $Purchase->txnid,	// Original transaction ID (txnid of original Purchase record)
					'txnid' => time(),			// Transaction ID for the VOID event
					'gateway' => $Gateway->module		// Gateway handler name (module name from @subpackage)
				));
			} else {
				shopp_add_order_event($Purchase->id,'void',array(
					'txnid' => $Purchase->txnid,
					'gateway' => $Gateway->module,
					'reason' => $reason,
					'user' => $user->ID,
					'note' => $message
				));
			}

			if ( ! empty($_POST['message']) )
				$this->addnote($Purchase->id,$_POST['message']);

			$Purchase->load_events();
		}

		if ( isset($_POST['billing']) && is_array($_POST['billing']) ) {

			$Purchase->updates($_POST['billing']);
			$Purchase->save();

		}

		if ( isset($_POST['shipping']) && is_array($_POST['shipping']) ) {

			$shipping = array();
			foreach( $_POST['shipping'] as $name => $value )
				$shipping[ "ship$name" ] = $value;

			$Purchase->updates($shipping);
			$Purchase->shipname = $shipping['shipfirstname'] . ' ' . $shipping['shiplastname'];

			$Purchase->save();
		}


		if ( isset($_POST['order-action']) && 'update-customer' == $_POST['order-action'] && ! empty($_POST['customer'])) {
			$Purchase->updates($_POST['customer']);
			$Purchase->save();
		}

		if ( isset($_POST['cancel-edit-customer']) ){
			unset($_POST['order-action'],$_POST['edit-customer'],$_POST['select-customer']);
		}

		// Create a new customer
		if ( isset($_POST['order-action']) && 'new-customer' == $_POST['order-action'] && ! empty($_POST['customer']) && ! isset($_POST['cancel-edit-customer'])) {
			$Customer = new ShoppCustomer();
			$Customer->updates($_POST['customer']);
			$Customer->password = wp_generate_password(12,true);
			if ( 'wordpress' == shopp_setting('account_system') ) $Customer->create_wpuser();
			else unset($_POST['loginname']);
			$Customer->save();
			if ( (int)$Customer->id > 0 ) {
				$Purchase->customer = $Customer->id;
				$Purchase->copydata($Customer);
				$Purchase->save();

				// New billing address, create record for new customer
				if ( isset($_POST['billing']) && is_array($_POST['billing']) && empty($_POST['billing']['id']) ) {
					$Billing = new BillingAddress($_POST['billing']);
					$Billing->customer = $Customer->id;
					$Billing->save();
				}

				// New shipping address, create record for new customer
				if ( isset($_POST['shipping']) && is_array($_POST['shipping']) && empty($_POST['shipping']['id']) ) {
					$Shipping = new ShippingAddress($_POST['shipping']);
					$Shipping->customer = $Customer->id;
					$Shipping->save();
				}

			} else $this->notice(Shopp::__('An unknown error occured. The customer could not be created.'), 'error');
		}

		if ( isset($_GET['order-action']) && 'change-customer' == $_GET['order-action'] && ! empty($_GET['customerid'])) {
			$Customer = new ShoppCustomer((int)$_GET['customerid']);
			if ( (int)$Customer->id > 0) {
				$Purchase->copydata($Customer);
				$Purchase->customer = $Customer->id;
				$Purchase->save();
			} else $this->notice(Shopp::__('The selected customer was not found.'), 'error');
		}

		if ( isset($_POST['save-item']) && isset($_POST['lineid']) ) {

			if ( isset($_POST['lineid']) && '' == $_POST['lineid'] ) {
				$lineid = 'new';
			} else $lineid = (int)$_POST['lineid'];

			$name = $_POST['itemname'];
			if ( ! empty( $_POST['product']) ) {
				list($productid, $priceid) = explode('-', $_POST['product']);
				$Product = new ShoppProduct($productid);
				$Price = new ShoppPrice($priceid);
				$name = $Product->name;
				if ( Shopp::__('Price & Delivery') != $Price->label )
					$name .= ": $Price->label";
			}

			// Create a cart representation of the order to recalculate order totals
			$Cart = new ShoppCart();

			$taxcountry = $Purchase->country;
			$taxstate = $Purchase->state;
			if ( ! empty($Purchase->shipcountry) && ! empty($Purchase->shipstate) ) {
				$taxcountry = $Purchase->shipcountry;
				$taxstate = $Purchase->shipstate;
			}
			ShoppOrder()->Tax->location($taxcountry, $taxstate);

			if ( 'new' == $lineid ) {
				$NewLineItem = new ShoppPurchased();
				$NewLineItem->purchase = $Purchase->id;
				$Purchase->purchased[] = $NewLineItem;
			}

			foreach ( $Purchase->purchased as &$Purchased ) {
				$CartItem = new ShoppCartItem($Purchased);

				if ( $Purchased->id == $lineid || ('new' == $lineid && empty($Purchased->id) ) ) {

					if ( ! empty( $_POST['product']) ) {
						list($CartItem->product, $CartItem->priceline) = explode('-', $_POST['product']);
					} elseif ( ! empty($_POST['id']) ) {
						list($CartItem->product, $CartItem->priceline) = explode('-', $_POST['id']);
					}

					$CartItem->name = $name;
					$CartItem->unitprice = Shopp::floatval($_POST['unitprice']);
					$Cart->additem((int)$_POST['quantity'], $CartItem);
					$CartItem = $Cart->get($CartItem->fingerprint());

					$Purchased->name = $CartItem->name;
					$Purchased->product = $CartItem->product;
					$Purchased->price = $CartItem->priceline;
					$Purchased->quantity = $CartItem->quantity;
					$Purchased->unitprice = $CartItem->unitprice;
					$Purchased->total = $CartItem->total;
					$Purchased->save();

				} else $Cart->additem($CartItem->quantity, $CartItem);

			}

			$Cart->Totals->register( new OrderAmountShipping( array('id' => 'cart', 'amount' => $Purchase->freight ) ) );

			$Purchase->total = $Cart->total();
			$Purchase->subtotal = $Cart->total('order');
			$Purchase->discount = $Cart->total('discount');
			$Purchase->tax = $Cart->total('tax');
			$Purchase->freight = $Cart->total('shipping');
			$Purchase->save();
			$Purchase->load_purchased();

		}

		if ( ! empty($_POST['save-totals']) ) {

			$totals = array();
			if ( ! empty($_POST['totals']) )
				$totals = $_POST['totals'];

			$objects = array(
				'tax' => 'OrderAmountTax',
				'shipping' => 'OrderAmountShipping',
				'discount' => 'OrderAmountDiscount'
			);

			$methods = array(
				'fee' => 'fees',
				'tax' => 'taxes',
				'shipping' => 'shipfees',
				'discount' => 'discounts'
			);

			$total = 0;
			foreach ( $totals as $property => $fields ) {
				if ( empty($fields) ) continue;

				if ( count($fields) > 1 ) {
					if ( isset($fields['labels']) ) {
						$labels = $fields['labels'];
						unset($fields['labels']);
						if ( count($fields) > count($labels) )
							$totalfield = array_pop($fields);

						$fields = array_combine($labels, $fields);
					}

					$fields = array_map(array('Shopp', 'floatval'), $fields);

					$entries = array();
					$OrderAmountObject = isset($objects[ $property ]) ? $objects[ $property ] : 'OrderAmountFee';
					foreach ( $fields as $label => $amount )
						$entries[] = new $OrderAmountObject(array('id' => count($entries) + 1, 'label' => $label, 'amount' => $amount));

					$savetotal = isset($methods[ $property ]) ? $methods[ $property ] : $fees;
					$Purchase->$savetotal($entries);

					$sum = array_sum($fields);
					if ( $sum > 0 )
						$Purchase->$property = $sum;

				} else $Purchase->$property = Shopp::floatval($fields[0]);

				$total += ('discount' == $property ? $Purchase->$property * -1 : $Purchase->$property );

			}

			$Purchase->total = $Purchase->subtotal + $total;
			$Purchase->save();
		}

		if ( ! empty($_GET['rmvline']) ) {
			$lineid = (int)$_GET['rmvline'];
			if ( isset($Purchase->purchased[ $lineid ]) ) {
				$Purchase->purchased[ $lineid ]->delete();
				unset($Purchase->purchased[ $lineid ]);
			}

			$Cart = new ShoppCart();

			$taxcountry = $Purchase->country;
			$taxstate = $Purchase->state;
			if ( ! empty($Purchase->shipcountry) && ! empty($Purchase->shipstate) ) {
				$taxcountry = $Purchase->shipcountry;
				$taxstate = $Purchase->shipstate;
			}
			ShoppOrder()->Tax->location($taxcountry, $taxstate);

			foreach ( $Purchase->purchased as &$Purchased )
				$Cart->additem($Purchased->quantity, new ShoppCartItem($Purchased));

			$Cart->Totals->register( new OrderAmountShipping( array('id' => 'cart', 'amount' => $Purchase->freight ) ) );

			$Purchase->total = $Cart->total();
			$Purchase->subtotal = $Cart->total('order');
			$Purchase->discount = $Cart->total('discount');
			$Purchase->tax = $Cart->total('tax');
			$Purchase->freight = $Cart->total('shipping');
			$Purchase->save();

			$Purchase->load_purchased();
		}


		if (isset($_POST['charge']) && $Gateway && $Gateway->captures) {
			if ( ! current_user_can('shopp_capture') )
				wp_die(__('You do not have sufficient permissions to carry out this action.','Shopp'));

			$user = wp_get_current_user();

			shopp_add_order_event($Purchase->id,'capture',array(
				'txnid' => $Purchase->txnid,
				'gateway' => $Purchase->gateway,
				'amount' => $Purchase->capturable(),
				'user' => $user->ID
			));

			$Purchase->load_events();
		}

		$targets = shopp_setting('target_markets');
		$default = array('' => '&nbsp;');
		$Purchase->_countries = array_merge($default, ShoppLookup::countries());

		$regions = Lookup::country_zones();
		$Purchase->_billing_states = array_merge($default, (array)$regions[ $Purchase->country ]);
		$Purchase->_shipping_states = array_merge($default, (array)$regions[ $Purchase->shipcountry ]);

		// Setup shipping carriers menu and JS data
		$carriers_menu = $carriers_json = array();
		$shipping_carriers = (array) shopp_setting('shipping_carriers'); // The store-preferred shipping carriers
		$shipcarriers = Lookup::shipcarriers(); // The full list of available shipping carriers
		$notrack = Shopp::__('No Tracking'); // No tracking label
		$default = get_user_meta(get_current_user_id(), 'shopp_shipping_carrier', true);

		if ( isset($shipcarriers[ $default ]) ) {
			$carriers_menu[ $default ] = $shipcarriers[ $default ]->name;
			$carriers_json[ $default ] = array($shipcarriers[ $default ]->name, $shipcarriers[ $default ]->trackpattern);
		} else {
			$carriers_menu['NOTRACKING'] = $notrack;
			$carriers_json['NOTRACKING'] = array($notrack, false);
		}

			$serviceareas = array('*', ShoppBaseLocale()->country());
			foreach ( $shipcarriers as $code => $carrier ) {
			if ( $code == $default ) continue;
			if ( ! empty($shipping_carriers) && ! in_array($code, $shipping_carriers) ) continue;
				if ( ! in_array($carrier->areas, $serviceareas) ) continue;
				$carriers_menu[ $code ] = $carrier->name;
				$carriers_json[ $code ] = array($carrier->name, $carrier->trackpattern);
			}

		if ( isset($shipcarriers[ $default ]) ) {
			$carriers_menu['NOTRACKING'] = $notrack;
			$carriers_json['NOTRACKING'] = array($notrack, false);
		}

		if ( empty($statusLabels) ) $statusLabels = array('');

		$Purchase->taxes();
		$Purchase->discounts();

		$columns = get_column_headers($this->id);
		$hidden = get_hidden_columns($this->id);

		include $this->ui('new.php');
	}

} // class ShoppScreenOrderEditor

class ShoppAdminOrderNotesBox extends ShoppAdminMetabox {

	protected $id = 'order-notes';
	protected $view = 'orders/notes.php';

	protected function title () {
		return Shopp::__('Notes');
	}

	protected function init () {

		add_filter('shopp_order_note', 'esc_html');
		add_filter('shopp_order_note', 'wptexturize');
		add_filter('shopp_order_note', 'convert_chars');
		add_filter('shopp_order_note', 'make_clickable');
		add_filter('shopp_order_note', 'force_balance_tags');
		add_filter('shopp_order_note', 'convert_smilies');
		add_filter('shopp_order_note', 'wpautop');

	}

	protected function request ( array &$post = array() ) {
		extract($this->references);

		$sent = false;

		if ( ! empty($post['send-note']) ){
			$user = wp_get_current_user();
			$sent = shopp_add_order_event($Purchase->id, 'note', array(
				'note' => $post['note'],
				'user' => $user->ID
			));

			$Purchase->load_events();
		}

		// Handle Order note processing
		if ( ! empty($post['note']) )
			$this->add($Purchase->id, stripslashes($post['note']), $sent);

		if ( ! empty($post['delete-note']) )
			$this->delete(key($post['delete-note']));


		if ( ! empty($post['edit-note']) ) {
			$id = key($post['note-editor']);
			if ( ! empty($post['note-editor'][ $id ]) )
				$this->edit($id, stripslashes($post['note-editor'][ $id ]));
		}

		$this->references['Notes'] = new ObjectMeta($Purchase->id, 'purchase', 'order_note');
	}

	private function add ( $order, $message, $sent = false ) {
		$user = wp_get_current_user();
		$Note = new ShoppMetaObject();
		$Note->parent = $order;
		$Note->context = 'purchase';
		$Note->type = 'order_note';
		$Note->name = 'note';
		$Note->value = new stdClass();
		$Note->value->author = $user->ID;
		$Note->value->message = stripslashes($message);
		$Note->value->sent = $sent;
		$Note->save();
	}

	private function delete ( $id ) {
		$Note = new ShoppMetaObject(array('id' => $id, 'type' => 'order_note'));
		if ( $Note->exists() )
			$Note->delete();

	}

	private function edit ( $id, $message ) {
		$Note = new ShoppMetaObject(array('id' => $id, 'type' => 'order_note'));
		if ( ! $Note->exists() ) return false;
		$Note->value->message = $message;
		$Note->save();
		return true;
	}

} // end class ShoppAdminOrderNotesBox


class ShoppAdminOrderHistoryBox extends ShoppAdminMetabox {

	protected $id = 'order-history';
	protected $view = 'orders/history.php';

	protected function title () {
		return Shopp::__('Order History');
	}

}

class ShoppAdminOrderDataBox extends ShoppAdminMetabox {

	protected $id = 'order-data';
	protected $view = 'orders/data.php';

	protected function title () {
		return Shopp::__('Details');
	}

	public static function name ( $name ) {
		echo esc_html($name);
	}

	public static function data ( $name, $data ) {

		if ( $type = Shopp::is_image($data) ) {
			$src = "data:$type;base64," . base64_encode($data);
			$result = '<a href="' . $src . '" class="shopp-zoom"><img src="' . $src . '" /></a>';
		} elseif ( is_string($data) && false !== strpos(data, "\n") ) {
			$result = '<textarea name="orderdata[' . esc_attr($name) . ']" readonly="readonly" cols="30" rows="4">' . esc_html($data) . '</textarea>';
		} else {
			$result = esc_html($data);
		}

		echo $result;

	}

}

class ShoppAdminOrderContactBox extends ShoppAdminMetabox {

	protected $id = 'order-contact';
	protected $view = 'orders/contact.php';

	protected function title () {
		return Shopp::__('Customer');
	}

}

class ShoppAdminOrderShippingAddressBox extends ShoppAdminMetabox {

	protected $id = 'order-shipping';
	protected $view = 'orders/shipping.php';

	protected function title () {
		return Shopp::__('Shipping Address');
	}

	public static function editor ( $Purchase, $type = 'shipping' ) {
		ob_start();
		include SHOPP_ADMIN_PATH . '/orders/address.php';
		return ob_get_clean();
	}

}

class ShoppAdminOrderBillingAddressBox extends ShoppAdminMetabox {

	protected $id = 'order-billing';
	protected $view = 'orders/billing.php';

	protected function title () {
		return Shopp::__('Billing Address');
	}

	public static function editor ( $Purchase, $type = 'billing' ) {
		shopp_custom_script('orders', 'var address = [];');
		ob_start();
		include SHOPP_ADMIN_PATH . '/orders/address.php';
		return ob_get_clean();
	}

}

class ShoppAdminOrderManageBox extends ShoppAdminMetabox {

	protected $id = 'order-manage';
	protected $view = 'orders/manage.php';

	protected function title () {
		return Shopp::__('Management');
	}

	public function box () {
		extract($this->references);
		$Gateway = $Purchase->gateway();
		$this->references['gateway_name'] = $Gateway ? $Gateway->name : '';
		$this->references['gateway_refunds'] = $Gateway ? $Gateway->refunds : false;
		$this->references['gateway_captures'] = $Gateway ? $Gateway->captures : false;
		parent::box();
	}

}