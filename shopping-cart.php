<?php

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
	if ( !wp_verify_nonce($nonce, 'shopping_cart_scripts_nonce')) die(__('Busted.') );

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
 *	Refresh/Build Shopping Cart
 */
function refresh_shopping_cart() {

	do_action('init');
	global $wpdb, $post, $stripe_options;

	// Nonce check
	$nonce = $_REQUEST['nonce'];
	if (!wp_verify_nonce($nonce, 'shopping_cart_scripts_nonce')) die(__('Busted.'));

	// http://www.php.net/manual/en/function.money-format.php
	setlocale(LC_MONETARY, 'en_US');

	// Grab all post IDs that should be in cart
	if ( isset($_REQUEST['products']) ) {
		$products = $_REQUEST['products'];
	}

	// Set subtotal of all product costs combined
	$grandSubtotal = 0;

	$html = "";
	$success = false;
	$productDescription = ""; // Build annotated description to pass to Stripe pipe(|) separated
	$cartDescription = ""; // Build a description of the cart containing the product and ID (comma and pipe separated)

	if ( isset($products) ) {
		foreach ( $products as $product ) {

			/**
			 *	Let's build the Shopping Cart!
			 */
			$itemID = ''; // Grab the product ID for use outside this loop
			$itemQty = ''; // Grab the product Qty for use outside this loop

			/**
			 *	Populate cart title and legend
			 */

			/**
			 *	Get Product Name/Post Data
			 */
			$postID = $product['postID'];
			$productsInCart = new WP_Query(array(
				'p' => $postID,
				'post_type' => 'products',
			));
			while($productsInCart->have_posts()) : $productsInCart->the_post();
				$currentPostID 			= $post->ID;
				$itemID 						= $currentPostID;
				$itemTitle 					= get_the_title();
				$cartDescription    = $cartDescription . $currentPostID . ','; // Add ID to cart description
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
			$cartDescription 		= $cartDescription . $itemColor . ',';
			$productDescription = $productDescription . $itemColor . ','; // Add Color to product description

			// # Product Quantity
			$itemQty = $product['qty'];
			$cartDescription		= $cartDescription . $itemQty; // Add quantity to cart description
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
	    $html .= '<div class="shopping-cart-product" data-jStorage-key="'.$product['key'].'">';
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

			// Generate a pipe between products & cart descriptors; never at the beginning or the end
			if ( $product != end( $products ) ) {
				$cartDescription = $cartDescription . '|';
				$productDescription = $productDescription . '|';
			}

			// Create delete cart item key
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
		'cartdescription' => $cartDescription
	));

	// Construct and send the response
	header("content-type: application/json");
	echo $response;
	exit;
}
add_action('wp_ajax_nopriv_refresh_shopping_cart', 'refresh_shopping_cart');
add_action('wp_ajax_refresh_shopping_cart', 'refresh_shopping_cart');

//Run Ajax calls even if user is logged in
if(isset($_REQUEST['action']) && ($_REQUEST['action']=='refresh_shopping_cart')):
	do_action( 'wp_ajax_' . $_REQUEST['action'] );
  do_action( 'wp_ajax_nopriv_' . $_REQUEST['action'] );
endif;

?>
