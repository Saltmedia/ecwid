<?php
/**
 * ECWID API script to create item options and combinations based on parent-child product relationships
 *
 * @copyright  Copyright (C) 2014 Saltmedia Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later;
 */
/* BEGIN Connect to API */
/*Get the code*/
$code= $_GET["code"];
/* Script URL */
$url = 'https://my.ecwid.com/api/oauth/token';
/* $_GET Parameters to Send */
$params = array('client_id' => 'your client id goes here', 'client_secret' => 'your client secret goes here', 'code' => $code, 'redirect_uri' =>'your redirect url goes here', 'grant_type' => 'authorization_code');
/* Update URL to container Query String of Paramaters */
$url .= '?' . http_build_query($params);
/* Set cURL Resource, options, and execute curl*/
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);
$data = curl_exec($ch);
/*get token in variable*/
$token=array_shift(json_decode($data,true));
$token_query_string=http_build_query(array('token'=>$token));
/* Optional troubleshoot by checking HTTP Code */
//$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
/* Close cURL Resource */
curl_close($ch);
/* END Connect to API */

/* Connect to MySQL database with product relationship table via PDO */
//In this example we have a mysql database named products with these columns as our data source: SKU, prod_id(ecwid product ID), Parentage (parent or child), Parent (SKU of parent if child), option_name, 3 columns for the option values - these column names match the value of the option_name column, ImageURL
//But you could use any source other such as CSV, etc.  We like MySQL since we can execute queries to make our life easier
$host = 'localhost';
$dbname = 'dbname';
$user = 'dbuser';
$pass = 'dbpassword';

$dbh = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);

// for each parent product find out the variation type and options and build array to pass to ecwid
//In this script, our parents already exist in ECWID with a product ID and we have that in our database already.
//If you were creating the parents as part of this process, the API would return the parent product ID after each POST

$sth=$dbh->prepare("
	SELECT SKU, prod_id, option_name FROM products WHERE Parentage='parent'
");
$sth->execute();
$parents=$sth->fetchAll(PDO::FETCH_ASSOC);

foreach ($parents as $parent) {
/*-----BEGIN Create Options-----*/
//Get the variation type and values from child objects
$option_column=$parent['option_name'];
//Our option values come from different columns based on the type of variation
$sth=$dbh->prepare("
	SELECT `{$option_column}`, sku from products WHERE Parent=:parent_sku
");
$sth->bindParam(':parent_sku', $parent['SKU']);
$sth->execute();
$choices=$sth->fetchAll(PDO::FETCH_NUM);

//build the choices array
//Example row array('text' => 'Silver Brass', 'priceModifier' => 0, 'priceModifierType' => ABSOLUTE),
$choice_array = array ();
foreach ($choices as $choice) {
	array_push($choice_array,array('text' => $choice[0], 'priceModifier' => 0, 'priceModifierType' => ABSOLUTE));
}
print_r($choice_array);

//Create option array with choices included
$product_option1 = array (
	'type' => 'SELECT',
	'name' => $parent['option_name'],
	'choices' => $choice_array,
	'defaultChoice' => 0,
	'required' => true
);

//If there were multiple options, we can add them here - in our case we only have one
$option_array=array($product_option1);

//Prepare the product updates.  We could modify other product aspects in the $ecwid_option_data but we're only adding options
$ecwid_product_updates = array(
 'options' => $option_array
);

//JSON encode payload, set headers, execute API PUT request
$json_data=json_encode($ecwid_product_updates);

$headers = array(
    'Content-Type: application/json;charset=utf-8',
	'Cache-Control: no-cache'
);

//make sure we're modifying the right product and add the token
$newurl = "https://app.ecwid.com/api/v3/5976094/products/".$parent['prod_id']."?" . $token_query_string;
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $newurl);
//This is the verb to update existing items
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);

// Send the request & save response to $result
$result = curl_exec($curl);

// Close request and print results
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);
/*-----END Create Options---------*/
/*-----BEGIN Create Combo---------*/
//First delete all pre-existing combos
$headers = array(
    'Content-Type: application/json;charset=utf-8',
	'Cache-Control: no-cache'
);
$newurl = "https://app.ecwid.com/api/v3/5976094/products/".$parent['prod_id']."/combinations?" . $token_query_string;
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $newurl);
curl_setopt($curl, CURLOPT_POST, false);
//This is the HTTP verb required to delete items
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

// Send the request & save response to $result
$result = curl_exec($curl);

//For each choice in the selection from the previous step, create combination:
foreach ($choices as $choice) {
//You could set inventory here, but we're using unlimited for now
$data = array(
//this line is name value pairs of selected options...we only have one option pair, but you could have multiples.  Example: both color and size.
 'options' => array(array('name'=>"{$option_column}",'value'=>"{$choice[0]}")),
 'sku' => $choice[1],
 'unlimited' => true
);	
$json_data=json_encode($data);

$headers = array(
    'Content-Type: application/json;charset=utf-8',
	'Cache-Control: no-cache'
);

//make sure we're modifying the right product and add the token
$newurl = "https://app.ecwid.com/api/v3/5976094/products/".$parent['prod_id']."/combinations?" . $token_query_string;
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $newurl);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);

// Send the request & save response to $result
$result_json = curl_exec($curl);
$result = json_decode($result_json, true);
//grab the id from the result, we're going to need this to upload a photo
$combo_id = $result['id'];

$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);	

//now let's upload a photo for each combo
//grab the photo from Amazon or whatever URL..check in db for image URL by SKU

$sth=$dbh->prepare("
	SELECT ImageURL FROM products WHERE SKU=:sku
");
$sth->bindParam(':sku', $choice[1]);
$sth->execute();
$image_url=$sth->fetchAll(PDO::FETCH_ASSOC);

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $image_url[0]['ImageURL']);
curl_setopt($curl, CURLOPT_HEADER, 1);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
//execute curl and then strip headers to leave us with binary image data
$file = curl_exec($curl);
curl_close($curl);
$file_array = explode("\n\r", $file, 2);
$photo_binary=substr($file_array[1], 1);

//Now let's upload the binary data to ECWID combo

$headers = array(
    'Content-Type: application/json',
	'Cache-Control: no-cache'
);

//make sure we're modifying the right product combo and add the token
$newurl = "https://app.ecwid.com/api/v3/5976094/products/".$parent['prod_id']."/combinations/".$combo_id."/image?" . $token_query_string;
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $newurl);
curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($curl, CURLOPT_POSTFIELDS, $photo_binary);


// Send the request & save response to $result
$result = curl_exec($curl);

// Close request and print results
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
//print_r(curl_getinfo($curl));
curl_close($curl);

//$result_arr = json_decode($result, true);
//print_r($result_arr);
/* END Create COMBO */
}

}

?>
