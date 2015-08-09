<?php
/**
 * Single Property Price
 *
 * @author 		BIOSTALL
 * @package 	PropertyHive/Templates
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

global $post, $property;
?>
<div class="price">

	<?php echo $property->get_formatted_price(); ?>
	
	<?php
       if ($property->department == 'residential-sales')
       {
           $term_list = wp_get_post_terms($post->ID, 'price_qualifier', array("fields" => "names"));
           echo '<span class="price-qualifier">' . $term_list[0] . '</span>';
       }
    ?>

</div>