<?php

/**
 * Hand Basket
 * A lightweight shopping basket that utilizes local storage.
 */
function handbasket_enqueue_scripts() {
  wp_enqueue_style( 'handbasket-styles', __FILE__ . '/css/handbasket.css' );
  // [jStorage] http://www.jstorage.info/
  wp_enqueue_script('jstorage-script', __FILE__ . '/js/jstorage.js', array('jquery','json2'));
  wp_enqueue_script('handbasket-scripts', __FILE__ . '/js/handbasket.js', array('jquery','json2'), true);
  wp_localize_script('handbasket-scripts', 'handbasket_scripts', array(
      'ajaxurl' => admin_url('admin-ajax.php',$protocol),
      'nonce' => wp_create_nonce('handbasket_scripts_nonce')
  ));
}
add_action('wp_enqueue_scripts', 'handbasket_enqueue_scripts');

/**
 *	Verify address using EasyPost API
 */
function easypost_verify_address() {
  /**
   *	Setup
   */
  do_action('init');
  global $wpdb, $post, $easypost_options;

  // Nonce check
  $nonce = $_REQUEST['nonce'];
  if ( !wp_verify_nonce($nonce, 'handbasket_scripts_nonce')) die(__('Busted.') );

  // Check some random variables to see if data is being sent...
  if ( isset( $_REQUEST['name'] ) && isset( $_REQUEST['streetOne'] ) && isset( $_REQUEST['zip'] ) ) :
    $name = strip_tags( trim( $_REQUEST['name'] ) );
    $streetOne = strip_tags( trim( $_REQUEST['streetOne'] ) );
    $streetTwo = strip_tags( trim( $_REQUEST['streetTwo'] ) );
    $city = strip_tags( trim( $_REQUEST['city'] ) );
    $state = strip_tags( trim( $_REQUEST['state'] ) );
    $zip = strip_tags( trim( $_REQUEST['zip'] ) );
  endif;

  /**
   * Verify Address
   */
  $errors = false;
  $success = false;

  // A. Establish EasyPost API keys & load library
  require_once( get_stylesheet_directory() . '/lib/easypost.php' );
  if ( isset($easypost_options['test_mode']) && $easypost_options['test_mode'] ) {
    \EasyPost\EasyPost::setApiKey( $easypost_options['test_secret_key'] );
  } else {
    \EasyPost\EasyPost::setApiKey( $easypost_options['live_secret_key'] );
  }

  try {

      // B. Retrieve this customer's mailing address...
      $to_address = \EasyPost\Address::create( array(
        "name"    => $name,
        "street1" => $streetOne,
        "street2" => $streetTwo,
        "city"    => $city,
        "state"   => $state,
        "zip"     => $zip,
      ));

      // C. Attempt to verify shipping address
      $verfied_address = $to_address->verify();
      $success = true;

  } catch ( Exception $e ) {
    // Error Notes:
    // bad State = Invalid State Code.
    // bad City = Invalid City.
    // bad address = Address Not Found.
    $easyPostFailStatus  = $e->getHttpStatus();
    $easyPostFailMessage = $e->getMessage();
    $errors = strval( $easyPostFailMessage );
    error_log($easyPostFailMessage);
  }

  /*
   * Build the response...
   */
  $response = json_encode(array(
    'success' => $success,
    'errors' => $errors,
  ));

  // Construct and send the response
  header("content-type: application/json");
  echo $response;
  exit;
}
add_action('wp_ajax_nopriv_easypost_verify_address', 'easypost_verify_address');
add_action('wp_ajax_easypost_verify_address', 'easypost_verify_address');

//Run Ajax calls even if user is logged in
if ( isset($_REQUEST['action']) && ($_REQUEST['action']=='easypost_verify_address') ):
  do_action( 'wp_ajax_' . $_REQUEST['action'] );
  do_action( 'wp_ajax_nopriv_' . $_REQUEST['action'] );
endif;


/**
 *	Refresh/Build Hand Basket
 */
function refresh_handbasket() {

  do_action('init');
  global $wpdb, $post, $stripe_options;

  // Nonce check
  $nonce = $_REQUEST['nonce'];
  if (!wp_verify_nonce($nonce, 'handbasket_scripts_nonce')) die(__('Busted.'));

  // http://www.php.net/manual/en/function.money-format.php
  setlocale(LC_MONETARY, 'en_US');

  // Grab all post IDs that should be in basket
  if ( isset($_REQUEST['products']) ) {
    $products = $_REQUEST['products'];
  }

  // Set subtotal of all product costs combined
  $grandSubtotal = 0;

  $html = "";
  $success = false;
  $productDescription = ""; // Build annotated description to pass to Stripe pipe(|) separated
  $basketDescription = ""; // Build a description of the basket containing the product and ID (comma and pipe separated)

  if ( isset($products) ) {
    foreach ( $products as $product ) {

      /**
       *	Let's build the Hand Basket!
       */
      $itemID = ''; // Grab the product ID for use outside this loop
      $itemQty = ''; // Grab the product Qty for use outside this loop

      /**
       *	Populate basket title and legend
       */

      /**
       *	Get Product Name/Post Data
       */
      $postID = $product['postID'];
      $productsInBasket = new WP_Query(array(
        'p' => $postID,
        'post_type' => 'products',
      ));
      while($productsInBasket->have_posts()) : $productsInBasket->the_post();
        $currentPostID 			= $post->ID;
        $itemID 						= $currentPostID;
        $itemTitle 					= get_the_title();
        $basketDescription    = $basketDescription . $currentPostID . ','; // Add ID to basket description
        $productDescription = $productDescription . $currentPostID . ','; // Add ID to product description
        $productDescription = $productDescription . get_the_title() . ','; // Add Title to product description
      endwhile;
      wp_reset_postdata();

      /**
       *	Get Product Options
       */

      // # Product Color
      $itemColor = $product['color'];
      if ( $itemColor == 'none' || $itemColor == 'undefined' ) {
        $itemColor = 'n/a';
      }
      $basketDescription 		= $basketDescription . $itemColor . ',';
      $productDescription = $productDescription . $itemColor . ','; // Add Color to product description

      // # Product Quantity
      $itemQty = $product['qty'];
      $basketDescription		= $basketDescription . $itemQty; // Add quantity to basket description
      $productDescription = $productDescription . $itemQty; // Add Quantity to product description

      // # Product Thumbnail
      $optionPreview = ''; // Clear variable during loop
      if ( have_rows( 'product_options', $postID ) ) :
      while ( have_rows( 'product_options', $postID ) ) : the_row();
        if ( get_sub_field('product_color_name') == $itemColor ) {
          $optionPreview = get_sub_field('product_checkout_image_preview');
        }
      endwhile;
      endif;

      /*
       * Generate User-facing totals
       */
      $optionPrice = ''; // Clear variable during loop
      $productPrice = get_field( 'product_price' );
      // Iterate through options to find the current options selected (looking for option based on color)
      if ( have_rows( 'product_options', $postID ) ) :
      while ( have_rows( 'product_options', $postID ) ) : the_row();
        if ( get_sub_field('product_color_name') == $itemColor ) {
          $optionPrice = get_sub_field('product_option_price');
        }
      endwhile;
      endif;

      // If cost of the option differs from the product price, set the product cost to the option amount
      if ( ( $optionPrice != $productPrice ) && ( $optionPrice != 0 ) ) {
        $actualPrice = $optionPrice;
      } else {
        $actualPrice = $productPrice;
      }

      // Generate Individual Product Subtotal
      $individualProductSubtotal = $actualPrice * $itemQty;

      // Add individual product subtotal to the grand subtotal
      $grandSubtotal += $individualProductSubtotal;

      /**
       *	Popover Output
       */
      $html .= '<div class="handbasket-product" data-jStorage-key="'.$product['key'].'">';
      $html .= 	'<span class="product-preview"><img src="'.$optionPreview.'" /></span>';
      $html .=	'<div class="product-description">';
      $html .= 		'<span class="product-title">'.$itemTitle.'</span>';
      $html .= 		'<span class="product-color" data-product-color="'.$itemColor.'"><span class="product-meta-title">Color: </span>'.$itemColor.'</span>';
      $html .= 	'</div>';
      $html .= 	'<span class="product-price" data-product-price="'.$actualPrice.'">'.format_money($actualPrice,'US').'</span>';
      $html .= 	'<span class="product-qty" data-product-qty="'.$itemQty.'">'.$itemQty.'</span>';
      $html .= '<span class="product-subtotal">'.format_money($individualProductSubtotal,'US').'</span>';

      /*
       * Cleanup
       */

      // Generate a pipe between products & basket descriptors; never at the beginning or the end
      if ( $product != end( $products ) ) {
        $basketDescription = $basketDescription . '|';
        $productDescription = $productDescription . '|';
      }

      // Create delete basket item key
      $html .= '<a href="javascript:void(0);" class="btn remove">x</a>';
      $html .= '</div>';

    } // foreach product

    /*
     * Let's build the Review Totals!
     */

    // Generate user readable versions of Totals
    // Subtotals
    //$subtotal_productPriceInDollars = money_format('%n', $grandSubtotal/100); // in 'dollars'

    // Tax
    $currenttaxrate = $stripe_options['tax_rate'];
    $tax = round($grandSubtotal * $currenttaxrate);
    //$tax_productPriceInDollars = money_format('%n', $tax/100); // in 'dollars'

    // Grand
    $grandTotal = intval($grandSubtotal + $tax);
    // $grand_productPriceInDollars = money_format('%n', $grandTotal/100); // in 'dollars'

    // Shipping information
    $shippingInfo = 'Free shipping for bags shipped within the U.S. offer valid on bags purchased from 192.168.10.40 only. Product ships from our warehouse within 1-2 business days via FedEx Home Delivery from Honolulu, Hawaii. We do not ship to PO boxes, please provide a physical address. Signature required upon delivery.';

    // Display Subtotal, Add Tax/Fees/Whatever & show Grand Total
    $html .= '<div class="checkout-totals">';
    $html .= '<div class="subtotal"><span class="total-title">Subtotal: </span><span class="line-item-cost">'.format_money($grandSubtotal,'US').'</span></div>';
    $html .= '<div class="auxfees"><span class="total-title">Tax ('.round((float)$currenttaxrate * 100, 3).'%): </span><span class="line-item-cost">'.format_money($tax,'US').'</span></div>';
    $html .= '<div class="auxfees"><span class="total-title">Shipping: </span><span class="line-item-cost">Free Domestic Shipping<a class="shipping-popover-trigger" data-toggle="tooltip" title="'.$shippingInfo.'" href="javascript:void(0);" ><i class="fa fa-info-circle"></i></a></span></div>';
    $html .= '<div class="total"><span class="total-title">Total: </span><span class="line-item-cost">'.format_money($grandTotal,'US').'</span></div>';
    $html .= '</div>';

    /**
     *	Generate checkout button as well as other promo text
     */

    $html .= '<hr />';
    $html .= '<span class="donation-promo-text">A portion of the profits donated to P&G PUR packets to provide safe drinking water around the world.</span>';
    $html .= '<a class="checkout">Checkout</a>';

  } // If products are being set
  /*
   * Build the response...
   */
  $success = true;
  $response = json_encode( array(
    'success' 				=> $success,
    'html' 						=> $html,
    'desc' 						=> $productDescription,
    'basketdescription' => $basketDescription
  ));

  // Construct and send the response
  header("content-type: application/json");
  echo $response;
  exit;
}
add_action('wp_ajax_nopriv_refresh_handbasket', 'refresh_handbasket');
add_action('wp_ajax_refresh_handbasket', 'refresh_handbasket');

//Run Ajax calls even if user is logged in
if(isset($_REQUEST['action']) && ($_REQUEST['action']=='refresh_handbasket')):
  do_action( 'wp_ajax_' . $_REQUEST['action'] );
  do_action( 'wp_ajax_nopriv_' . $_REQUEST['action'] );
endif;

function render_handbasket() {

  /*
   * "Checkout" Modal
   */
  echo '<div id="checkoutModal" class="modal fade" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">';
  echo 	'<div class="container">';
  echo 		'<div class="modal-meta">';
  echo 			'<a class="site-title" href="'.home_url( '/' ).'" title="'. esc_attr( get_bloginfo( 'name', 'display' ) ) .'" rel="home">'.get_bloginfo( 'name' ).'</a>';
  echo  		'<button type="button" class="close" data-dismiss="modal" aria-hidden="true">X</button>';
  echo 		'</div>'; // .modal-meta

  /**
   *	Checkout Headers
   */
  echo  '<div class="modal-header">';

  echo 		'<div class="checkoutReview checkoutControls show">';
  echo  		'<div class="step-count">1 of 3</div>';
  echo    	'<h3 class="checkoutTitle">'. __('Review Your Basket','litton_bags') .'</h3>';
  echo    '</div>';

  echo 		'<div class="checkoutBasic hide">';
  echo  		'<div class="step-count">2 of 3</div>';
  echo    	'<h3 class="checkoutTitle">'. __('Basic Information','litton_bags') .'</h3>';
  echo 		'</div>';

  echo 		'<div class="checkoutPay hide">';
  echo  		'<div class="step-count">3 of 3</div>';
  echo    	'<h3 class="checkoutTitle">'. __('Submit Your Payment','litton_bags') .'</h3>';
  echo 		'</div>';

  echo 		'<div class="checkoutProcessing hide">';
  echo  		'<div class="step-count">Payment & Shipping Being Caluculated</div>';
  echo    	'<h3 class="checkoutTitle">'. __('Submitting Basket Info','litton_bags') .'</h3>';
  echo 		'</div>';

  echo 		'<div class="checkoutResult hide">';
  echo    	'<h3 class="checkoutTitle"></h3>';
  echo 		'</div>';

  echo  '</div>';


  /**
   *	Stripe Checkout Content
   */
  echo  '<div class="modal-body">';

  /**
   *	A. Checkout Step One: Review
   */
  echo 	'<div class="checkoutReview show"></div>';

  /**
   *	B. Checkout Step Two: Basic Info / Pay
   */
  echo 	'<div class="checkoutBasicAndPay hide">';
    // "STRIPE Variables
    // $productPrice = get_field('product_price'); // in 'cents'
    // $productPriceInDollars = $productPrice/100; // in 'dollars'
    // $english_notation = number_format($productPriceInDollars,2,'.',''); // in eng notation 'dollars'

    if( isset($_GET['payment']) && $_GET['payment'] == 'paid') {
      echo '<p class="success">' . __('Thank you for your payment.', 'litton_bags') . '</p>';
    } else {

      // "Stripe": Basic/Payment Form
      echo '<form action="" method="POST" id="stripe-payment-form">';

      // 		FORM ERRORS
      echo '<div class="payment-errors alert hide"></div>';

      /**
       *	B.1. Basic Info Collection
       */
      // 		PERSONAL INFO
      echo 	'<div class="form-row checkoutBasic basic-info" id="basic-info" >';
      echo 	'<legend>Basic Information</legend>';
      echo 		'<label>'. __('Full Name', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" name="customer-name" />';
      echo 		'<label>'. __('Email Address', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" class="email" name="email" />'; // ARE WE DOING THIS CORRECTLY?!
      echo 	'</div>';

      //		CC ADDRESS COLLECTION
      echo 	'<div class="form-row checkoutBasic basic-info" id="addr-info">';
      echo 		'<legend>Billing Address</legend>';
      echo 		'<label>'. __('Address Line 1', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" data-stripe="address-line1" class="address" />';
      echo 		'<label>'. __('Address Line 2', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" data-stripe="address-line2" class="optional address" />';
      echo  	'<div class="form-row-single">';
      echo 			'<div>';
      echo 				'<label>'. __('City', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" data-stripe="address-city" />';
      echo 			'</div>';
      echo 			'<div>';
      echo 				'<label>'. __('Zip Code', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" class="zip-code" data-stripe="address-zip" />';
      echo 			'</div>';
      echo 			'<div>';
      echo 				'<label>'. __('State', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" class="state" data-stripe="address-state" />';
      echo 			'</div>';
      echo 			'<div>';
      echo 				'<label>'. __('Country', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" class="country" data-stripe="address-country" />';
      echo 			'</div>';
      echo 		'</div>'; // .form-row-single

      echo   	'<span class="formHelperText">Currently, we are only shipping to the United States on our website. Please email us for international purchases.</span>';
      echo 		'<br />';
      echo 		'<input id="shippingIsDifferent" type="checkbox" />';
      echo   	'<span class="formHelperText">My shipping address is different from my billing address.</span>';
      echo 	'</div>';

      //		SHIPPING ADDRESS COLLECTION
      echo 	'<div class="form-row basic-info shippingInfo hide" id="addr-info-shipping">';
      echo 		'<legend>Shipping Address</legend>';
      echo 		'<label>'. __('Address Line 1', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" data-easypost="shipping-address-line1" name="shipping-address-line1" class="address" />';
      echo 		'<label>'. __('Address Line 2', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" data-easypost="shipping-address-line2" name="shipping-address-line2" class="address optional" />';
      echo  	'<div class="form-row-single">';
      echo 			'<div>';
      echo 				'<label>'. __('City', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" data-easypost="shipping-address-city" name="shipping-address-city" />';
      echo 			'</div>';
      echo 			'<div>';
      echo 				'<label>'. __('State', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" class="state" data-easypost="shipping-address-state" name="shipping-address-state" />';
      echo 			'</div>';
      echo 			'<div>';
      echo 				'<label>'. __('Zip Code', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" class="zip-code" data-easypost="shipping-address-zip" name="shipping-address-zip" />';
      echo 			'</div>';
      echo 			'<div>';
      echo 				'<label>'. __('Country', 'litton_bags') .'</label>';
      echo 				'<input type="text" size="20" autocomplete="off" class="country" data-easypost="shipping-address-country" name="shipping-address-country" />';
      echo 			'</div>';
      echo 		'</div>'; // .form-row-single
      echo 	'</div>';

      // 		CARD NUMBER
      echo 	'<div class="form-row checkoutPay payment-info hide" id="cc-info">';
      echo 		'5% of your purchase will go to the charity WakaWaka Lights.';
      echo 		'<legend>Card Information</legend>';
      echo 		'<div class="cc-icons">';
      echo 			'<div class="cc-icon visa"></div>';
      echo 			'<div class="cc-icon mastercard"></div>';
      echo 			'<div class="cc-icon amex"></div>';
      echo 			'<div class="cc-icon discover"></div>';
      echo 			'<div class="cc-icon jcb"></div>';
      echo 		'</div>';
      echo 		'<label>'. __('Name on Card', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" data-stripe="name" />';
      echo 		'<label>'. __('Card Number', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="20" autocomplete="off" class="cc-num" data-stripe="number" />';
      echo 		'<label>'. __('CVC', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="4" autocomplete="off" class="cc-cvc" data-stripe="cvc" />';
      echo 		'<label>'. __('Expiration (MM/YYYY)', 'litton_bags') .'</label>';
      echo 		'<input type="text" size="2" data-stripe="exp-month" class="cc-exp-month" data-numeric />';
      echo 		'<span> / </span>';
      echo 		'<input type="text" size="4" data-stripe="exp-year" class="cc-exp-year" data-numeric />';
      echo 	'</div>';

      //		WORDPRESS DATA VALUES (NO SENSITIVE FORMS BELOW THIS LINE!)
      echo 	'<input type="hidden" name="action" value="stripe"/>';
      echo 	'<input type="hidden" name="redirect" value="'. get_permalink() .'"/>';
      echo 	'<input type="hidden" name="stripe_nonce" value="'. wp_create_nonce('stripe-nonce').'"/>';
      echo 	'<input type="hidden" name="description" value=""/>';
      echo 	'<input type="hidden" name="basketdescription" value=""/>';
      echo 	'<button type="submit hidden" class="hide" id="stripe-submit">'. __('Submit Payment', 'litton_bags') .'</button>';
      echo '</form>';
    }
  echo  '</div>'; // Pay

  // Checkout Step Three: "Processing..."
  echo 	'<div class="checkoutProcessing hide">';
  echo  '<div class="spinner large"></div>';
  echo  '<p>Please wait for your payment to process. Refrain from closing this page to avoid multiple charges.</p>';
  echo  '</div>';

  // Checkout Step Four: Thank You
  echo 	'<div class="checkoutResult hide">';
  echo   '<div class="result-wrapper"><img class="success" src="'.get_stylesheet_directory_uri().'/images/payment-success-alpha.jpg" /></div>';
  echo   '<p class="result-message"></p>';
  echo  '</div>';

  echo  '</div>'; // .modal-body
  echo  '<div class="modal-footer">';
  echo 		'<div class="checkoutReview checkoutControls show">';
  //				PayPal Checkout Option
  echo 			'<a class="paypal-checkout" href="javascript:void(0);" title="Checkout via Paypal instead." data-payment-method="paypal"><img src="'.get_stylesheet_directory_uri().'/images/paypal-checkout.png" alt="Checkout via Paypal instead." /></a>';
  //echo    '<a class="btn btn-primary btn-primary-checkout choosePaymentMethod">Select Payment Method</a>';
  echo 		'</div>';
  echo 		'<div class="checkoutBasic checkoutControls hide">';
  echo    	'<a id="submitBasicInfo" class="btn btn-primary btn-primary-checkout">Submit Basic Info</a>'; // [completes step B.1]
  echo  		'<div class="spinner"></div>';
  echo 		'</div>';
  echo 		'<div class="checkoutPay checkoutControls hide">';
  echo    	'<a class="btn btn-primary btn-primary-checkout submitPayment">Submit Your Payment</a>';
  echo  		'<div class="spinner"></div>';
  echo 			'<a class="paypal-checkout"><img src="'.get_stylesheet_directory_uri().'/images/paypal-checkout.png" alt="Checkout via Paypal instead." /></a>';
  echo  	'</div>';
  echo 		'<div class="checkoutResult checkoutControls hide">';
  echo    	'<a class="btn btn-primary hide showBasicInfo">Review Basic Info Screen</a>'; // Review Basic Info
  echo    	'<a class="btn btn-primary hide showSubmitPayment">Review Payment Screen</a>'; // Review Payment Screen
  //echo    	'<a class="btn btn-primary closeCheckout" data-dismiss="modal" aria-hidden="true">Close</a>';
  echo  	'</div>';
  echo  '</div>'; // .modal-footer

  // Services Used (i.e. Stripe & EasyPost)
  echo '<div class="services-used-container">';
  //echo 	'<div class="services-used stripe"><a href="http://stripe.com" target="_blank"><i class="stripe-icon"></i></a></div>';
  echo 	'<div class="services-used support">Having trouble with your checkout? <a href="mailto:support@littonbags.com">Contact our support team.</a></div>';
  echo '</div>';

  echo '</div>'; // .container

  // Loading Overlay
  echo '<div class="overlay loading"><i class="spinner medium"></i><div class="overlay-message-container"><h4>Preparing to go to PayPal</h4></div></div>';

  echo '</div>'; // .modal (#checkout)

}
?>


 ?>
