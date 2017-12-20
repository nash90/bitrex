<?php 
//Building A Simple Bittrex Bot: 0.0005
echo("\n######### START BOT ############\n");
$file_settings = "settings.conf";
$file_alt_settings = "alt_settings.conf";

//get settings from file and decode to array
$app_settings = file_get_contents($file_settings);
$app_settings = json_decode($app_settings);

$alt_app_settings = file_get_contents($file_alt_settings);
$alt_app_settings = json_decode($alt_app_settings);

//set the variable with value from settings
$run_buy_logic = $app_settings->app_flag->run_buy_logic_flag;
$run_sell_logic = $app_settings->app_flag->run_sell_logic_flag;
$run_risk_sell_logic = $app_settings->app_flag->run_risk_sell_logic_flag;
$auto_rate_selection_flag = $app_settings->app_flag->auto_rate_selection_flag;
$retry_risk_sell = $app_settings->app_flag->retry_risk_sell;

$apikey=getenv('BITREX_API_KEY');
$apisecret=getenv('BITREX_API_SECRET');

$default_currency = $app_settings->currency->default_currency;
$target_currency = $app_settings->currency->target_currency;

$buy_rate = $app_settings->fixed_rate->fixed_buy_rate;
$sale_rate = $app_settings->fixed_rate->fixed_sale_rate;
$avoid_rate = $app_settings->fixed_rate->fixed_avoid_rate;

$stop_buy_after_risk_max_count = $app_settings->others->max_buy_after_risk_count;
$default_balance = $app_settings->others->default_balance;
//$file_risk_count = 'risk_sell_logic_count.txt';


//init global variables 
$target_balance = 0;
$current_rate = 0;
$open_order = false;
$trade_count = 0;
$stop_buy_after_risk = false;
$buy = false;
$sell = false;
$db = null;
$run_risk_count = 0;
$rates_array = null;


//Common logs
function getCommonLogs(){
    global $default_currency, $target_currency; 
    global $target_balance, $default_balance, $remote_balance;
    global $buy_rate, $current_rate, $sale_rate, $avoid_rate; 
    global $run_risk_count, $alt_app_settings, $stop_buy_after_risk; 
    global $open_order_result, $open_order;
    global $buy, $sell;

    echo("\n\nThe rate of coin ".$default_currency.'-'.$target_currency." is : ".$current_rate."\n");
    echo("My buy rate in BTC is : ".$buy_rate."\n");
    echo("My sale rate in BTC is : ".$sale_rate."\n");
    echo("My avoid rate in BTC is : ".$avoid_rate."\n\n");
    echo("My default balance in ".$default_currency.' : '.$default_balance."\n");
    echo("My remote balance in ".$default_currency.' : '.$remote_balance."\n");
    echo("My target balance in ".$target_currency.' : '.$target_balance."\n\n");
    echo("Open order size : ".count($open_order_result)."     ");
    echo("Open order flag : ".json_encode($open_order)."\n\n");
    echo("Buy Flag : ".json_encode($buy)."     ");
    echo("Sale Flag : ".json_encode($sell)."\n");
    echo("Approx Buy Quantity : ".getBuyQuantity($buy_rate, $default_balance)."     ");
    echo("Sell Quantity : ".getSellQuantity($sale_rate, $target_balance)."\n");
    echo("RUN_RISK_SELL_LOGIC_COUNT :".$run_risk_count."     ");
    echo("stop run risk flag :".json_encode($stop_buy_after_risk)."\n");
    echo("\n######### END BOT ############\n");
}

// ALL API Calls
function bittrexbalance($apikey, $apisecret, $currency){
    $nonce=time();
    $api_endpoint ='https://bittrex.com/api/v1.1/account/getbalance';
    $uri=$api_endpoint.'?apikey='.$apikey.'&currency='.$currency.'&nonce='.$nonce;
    $sign=hash_hmac('sha512',$uri,$apisecret);
    $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('apisign:'.$sign));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $execResult = curl_exec($ch);
    $obj = json_decode($execResult, true);
    //print_r($obj);echo("\n");
    $balance = $obj["result"]["Available"];
    return $balance;
}

function getMarketCap($limit){
    //fetch top 50 cryptos by marketcap
    $cnmkt = "https://api.coinmarketcap.com/v1/ticker/?limit=".$limit;
    $fgc = json_decode(file_get_contents($cnmkt), true);
    return $fgc;
}

function getMarketInfo($fromCurrency, $toCurrency){
    
    $api_endpoint ='https://bittrex.com/api/v1.1/public/getmarketsummary';
    $uri=$api_endpoint.'?market='.$fromCurrency.'-'.$toCurrency;
    $ch = curl_init($uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $execResult = curl_exec($ch);
    $obj = json_decode($execResult, true);
    //$rate = 2;
    $rate = $obj["result"][0]["Last"];
    //print_r($obj);echo("\n");
    //print_r($uri);echo("\n");
    return $rate;

}

function getAllOpenOrdersFromApi($apikey, $apisecret, $fromCurrency, $toCurrency){
    //https://bittrex.com/api/v1.1/market/getopenorders?apikey=API_KEY&market=BTC-LTC  
    $nonce= '&nonce='.time(); 
    $api_endpoint ='https://bittrex.com/api/v1.1/market/getopenorders';
    $uri=$api_endpoint.'?apikey='.$apikey.'&market='.$fromCurrency.'-'.$toCurrency.'&nonce='.$nonce;
    $sign=hash_hmac('sha512',$uri,$apisecret);
    $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('apisign:'.$sign));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $execResult = curl_exec($ch);
    $obj = json_decode($execResult, true);
    //print_r($obj);echo("\n");
    return $obj;
}

function getOrderDetail($apikey, $apisecret, $order_id){
    //https://bittrex.com/api/v1.1/account/getorder?apikey=API_KEY&uuid=ORDER_ID&nonce=1234
    $nonce= '&nonce='.time(); 
    $api_endpoint ='https://bittrex.com/api/v1.1/account/getorder';
    $uri=$api_endpoint.'?apikey='.$apikey.'&uuid='.$order_id.'&nonce='.$nonce;
    $sign=hash_hmac('sha512',$uri,$apisecret);
    $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('apisign:'.$sign));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $execResult = curl_exec($ch);
    $obj = json_decode($execResult, true);
    return $obj;
}

function buyCoin($apikey, $apisecret, $fromCurrency, $toCurrency, $quantity, $rate){
    //https://bittrex.com/api/v1.1/market/buylimit?apikey=API_KEY&market=BTC-LTC&quantity=1.2&rate=1.3
    $nonce= '&nonce='.time(); 
    $api_endpoint ='https://bittrex.com/api/v1.1/market/buylimit';
    $uri=$api_endpoint.'?apikey='.$apikey.'&market='.$fromCurrency.'-'.$toCurrency.'&quantity='.$quantity.'&rate='.$rate.'&nonce='.$nonce;
    $sign=hash_hmac('sha512',$uri,$apisecret);
        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('apisign:'.$sign));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $execResult = curl_exec($ch);
    $obj = json_decode($execResult, true);
    print_r($obj);echo("buyCoin\n");
    return $obj;

}

function sellCoin($apikey, $apisecret, $fromCurrency, $toCurrency, $quantity, $rate){
    //https://bittrex.com/api/v1.1/market/selllimit?apikey=API_KEY&market=BTC-LTC&quantity=1.2&rate=1.3  
    $nonce= '&nonce='.time(); 
    $api_endpoint ='https://bittrex.com/api/v1.1/market/selllimit';
    $uri=$api_endpoint.'?apikey='.$apikey.'&market='.$fromCurrency.'-'.$toCurrency.'&quantity='.$quantity.'&rate='.$rate.'&nonce='.$nonce;
    $sign=hash_hmac('sha512',$uri,$apisecret);
        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('apisign:'.$sign));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $execResult = curl_exec($ch);
    $obj = json_decode($execResult, true);
    print_r($obj);echo("sellCoin\n");
    return $obj;

}

function cancel_open_order($apikey, $apisecret, $order_id){
    //https://bittrex.com/api/v1.1/market/cancel?apikey=API_KEY&uuid=ORDER_UUID 
    echo("\n######### EXECUTED CANCEL ORDER ACTION ############\n");
    $nonce= '&nonce='.time(); 
    $api_endpoint ='https://bittrex.com/api/v1.1/market/cancel';
    $uri=$api_endpoint.'?apikey='.$apikey.'&uuid='.$order_id.$nonce;
    $sign=hash_hmac('sha512',$uri,$apisecret);
        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('apisign:'.$sign));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $execResult = curl_exec($ch);
    $obj = json_decode($execResult, true);
    print_r($obj);echo("cancel_open_order\n");

    return $obj;
}

// All the utilities functions
function getSellQuantity($rate, $balance){
    $quantity = $balance;
    return $quantity;
}

function getBuyQuantity($rate, $balance){
    $quantity = $balance / $rate;
    return $quantity;
}

function buyAction ($apikey, $apisecret, $default_currency, $target_currency, $buy_quantity, $buy_rate){
    echo("\n######### EXECUTED BUY ACTION ############\n");
    global $open_order, $rates_array;
    
    $buy_coin = buyCoin($apikey, $apisecret, $default_currency, $target_currency, $buy_quantity, $buy_rate);
    if($buy_coin["success"] == true){
        echo("\n".$buy_coin["result"]["uuid"]." API Buy Success at rate: ".$buy_rate." : ".$default_currency.'-'.$target_currency." : ".$buy_quantity."\n");
        $uuid = $buy_coin["result"]["uuid"];
        $type = "LIMIT_BUY";
        $open_flag = 1;
        $rates_json = json_encode($rates_array, JSON_PRETTY_PRINT);
        $sql =<<<EOF
INSERT INTO APP_ORDER (ORDER_UUID,ORDER_TYPE,OPEN_FLAG,RATE_JSON)
VALUES ('$uuid', '$type', $open_flag, '$rates_json');
EOF;
        //echo $sql;
        run_sql($sql, true);
    }else{
        echo("\nAPI Buy Failed at rate: ".$buy_rate." : ".$default_currency.'-'.$target_currency." : ".$buy_quantity."\n");
    }
    $open_order = true;
}

function sellAction($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $sale_rate){
    echo("\n######### EXECUTED SELL ACTION ############\n");
    global $open_order, $rates_array;
    
    $sell_coin = sellCoin($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $sale_rate);
    if($sell_coin["success"] == true) {
        echo("\n".$sell_coin["result"]["uuid"]." API Sell Success at rate: ".$sale_rate." : ".$default_currency.'-'.$target_currency." : ".$sell_quantity."\n");
        $uuid = $sell_coin["result"]["uuid"];
        $type = "LIMIT_SELL";
        $open_flag = 1;
        $rates_json = json_encode($rates_array, JSON_PRETTY_PRINT);
        $sql =<<<EOF
INSERT INTO APP_ORDER (ORDER_UUID,ORDER_TYPE,OPEN_FLAG,RATE_JSON)
VALUES ('$uuid', '$type', $open_flag, '$rates_json');
EOF;
        //echo $sql;
        run_sql($sql, true);
    }else {
        echo("\nAPI Sell Failed at rate: ".$sale_rate." : ".$default_currency.'-'.$target_currency." : ".$sell_quantity."\n");
    }
    $open_order = true;
}

function riskSellAction($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $current_rate){
    echo("\n######### EXECUTED RISK SELL ACTION ############\n");
    global $open_order;
    $sell_coin = sellCoin($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $current_rate);
    if($sell_coin["success"] == true) {
        echo("\n".$sell_coin["result"]["uuid"]." API Risk Sell Success at rate: ".$current_rate." : ".$default_currency.'-'.$target_currency." : ".$sell_quantity."\n");
    }else {
        echo("\nAPI Risk Sell Failed at rate: ".$current_rate." : ".$default_currency.'-'.$target_currency." : ".$sell_quantity."\n");
    }
    $open_order = true;
}

function log_in_file($file_name, $content){
    file_put_contents($file_name, $content, FILE_APPEND | LOCK_EX);
}

function run_sql($sql, $log_sql){
    global $db;

    if ($log_sql) echo $sql."\n\n";
    $ret = $db->exec($sql);
    if(!$ret){
      echo $db->lastErrorMsg();
    } else {
      //echo "sql execution success\n"; 
    }
}

function querySql($sql){
    global $db;

    echo $sql."\n\n";
    $ret = $db->query($sql);
    if(!$ret){
      echo $db->lastErrorMsg();
    } else {
      //echo "sql Query success\n";
    }

    return $ret;
}

function getOrderData($obj){
    $data = [];
   while($row = $obj->fetchArray(SQLITE3_ASSOC) ) {
        $item = array(
            "ID" => $row["ID"],
            "ORDER_UUID" => $row["ORDER_UUID"],
            "ORDER_TYPE" => $row["ORDER_TYPE"],
            "OPEN_FLAG" => $row["OPEN_FLAG"],
            "RESPONSE_JSON" => $row["RESPONSE_JSON"],
            "RATE_JSON" => $row["RATE_JSON"]
            );
        array_push($data, $item);
   }

   return $data;
}

function setAutoRates(){
    global $buy_rate, $sale_rate, $avoid_rate, $current_rate;
    global $alt_app_settings;
    global $buy, $sell, $open_order;
    if($buy && !$open_order){
        $alt_buy_rate = $current_rate - (($alt_app_settings->rate_percent->buy_percent/100) * $current_rate);
        $buy_rate = round($alt_buy_rate, 8);
        $alt_app_settings->alt_rate->alt_buy_rate = $alt_buy_rate;

        $alt_sale_rate = $current_rate + (($alt_app_settings->rate_percent->sale_percent/100) * $current_rate);
        $sale_rate = round($alt_sale_rate, 8);
        $alt_app_settings->alt_rate->alt_sale_rate = $alt_sale_rate; 

        $alt_avoid_rate = $current_rate - (($alt_app_settings->rate_percent->avoid_percent/100) * $current_rate);
        $avoid_rate = round($alt_avoid_rate, 8);
        $alt_app_settings->alt_rate->alt_avoid_rate = $alt_avoid_rate;
    }

    if($sell || $open_order){
        $buy_rate = $alt_app_settings->alt_rate->alt_buy_rate;
        $sale_rate = $alt_app_settings->alt_rate->alt_sale_rate;
        $avoid_rate =  $alt_app_settings->alt_rate->alt_avoid_rate;
    }

}

function setBuySellFlag($target_balance, $default_balance){
    global $buy, $sell;

    if ($target_balance > 0.0000000099) {
        $sell = true;
    }else {
        if($default_balance > 0.0005){
            $buy = true;
        }
    }
}

function initDB(){
    global $db;

    $db = new MyDB();
    if(!$db) {
      echo $db->lastErrorMsg();
    } else {
      echo "Opened database successfully\n";
    }

$sql =<<<EOF
CREATE TABLE IF NOT EXISTS APP_ORDER
(ID INTEGER PRIMARY KEY NOT NULL,
ORDER_UUID CHAR(50) NOT NULL,
ORDER_TYPE TEXT NOT NULL,
OPEN_FLAG BIT NOT NULL,
RESPONSE_JSON VARCHAR,
RATE_JSON VARCHAR);
EOF;

    run_sql($sql, false);
}

function getOpenOrderFromDB(){
    $open_order_sql = "SELECT * FROM APP_ORDER WHERE OPEN_FLAG=1;"; 
    $open_order_db = querySql($open_order_sql);

    return ($open_order_db) ? getOrderData($open_order_db) : [];
}

// Db starter class
class MyDB extends SQLite3 {
  function __construct() {
     $this->open('app.db');
  }
}

/////Main run pipeline

initDB();
//Actions get balance
$remote_balance = bittrexbalance($apikey, $apisecret, $default_currency);
$target_balance = bittrexbalance($apikey, $apisecret, $target_currency);

//Action get current rate
$current_rate = getMarketInfo($default_currency, $target_currency);

//Action get Open order and set open order flag
$open_order_result  = getOpenOrderFromDB();
if(count($open_order_result) > 0 ){
        $open_order = true; 
    } else {
        $open_order = false;
    }

//Set To Buy Or To Sell Flag
setBuySellFlag($target_balance, $default_balance);

//check count of risk sell and stop if max exceed to escape free fall trend
$run_risk_count = (int) $alt_app_settings->others->current_buy_after_risk_count;  
if($run_risk_count >= $stop_buy_after_risk_max_count){
    $stop_buy_after_risk = true;
}

// Set up rates automatically if flag for it is On
if($auto_rate_selection_flag){
    setAutoRates();
}
$rates_array = array(
    "buy_rate" => $buy_rate,
    "sale_rate" => $sale_rate,
    "avoid_rate" => $avoid_rate,
    "current_rate" => $current_rate
    );

//When a open order already exist perform following logics
if($open_order) {
    echo("open order already exists!!! \n");
    //print_r($open_order_obj);
    foreach($open_order_result as $key){
        $order_id = $key["ORDER_UUID"];
        if($auto_rate_selection_flag){
            print_r($key["RATE_JSON"]);
            $rates_array = json_decode($key["RATE_JSON"], true);
            $buy_rate = $rates_array["buy_rate"];
            //$sale_rate = $rates_array["sale_rate"];
            //$avoid_rate = $rates_array["avoid_rate"];
        }

        $order_detail = getOrderDetail($apikey, $apisecret, $order_id);
        $order_detail_json = json_encode($order_detail, JSON_PRETTY_PRINT);
        print_r($order_detail_json);
        $order_detail = $order_detail["result"];

        if($order_detail["IsOpen"]){
            if($order_detail["Type"] == "LIMIT_SELL"){

                if($order_detail["Limit"] > $avoid_rate){
                    if($current_rate <= $avoid_rate){
                        cancel_open_order($apikey, $apisecret, $order_id);
                    }else if(/*!$auto_rate_selection_flag && */$order_detail["Limit"] !== $sale_rate){
                        cancel_open_order($apikey, $apisecret, $order_id);
                    }
                }

                if($order_detail["Limit"] <= $avoid_rate){
                    if($retry_risk_sell && $order_detail["Limit"] > $current_rate){
                        cancel_open_order($apikey, $apisecret, $order_id);
                    }
                }
            }

            if($order_detail["Type"] == "LIMIT_BUY"){
                if(!$auto_rate_selection_flag && $order_detail["Limit"] !== $buy_rate){
                    cancel_open_order($apikey, $apisecret, $order_id);
                    echo("Cancelled because Limit did not match current buy rate \n");
                }

                if($auto_rate_selection_flag){
                    //$check_rate = $sale_rate;
                    $check_rate = $sale_rate + (($alt_app_settings->rate_percent->buy_percent/100) * $sale_rate);
                    echo("Check Rate is :".$check_rate."\n");
                    if($current_rate > $check_rate){
                        cancel_open_order($apikey, $apisecret, $order_id);
                        echo("Cancelled because the current rate exceed the check_rate \n");
                    }
                }
            }            

        }
        if($order_detail["IsOpen"] === false){
            $sql = "UPDATE APP_ORDER SET OPEN_FLAG=0, RESPONSE_JSON='$order_detail_json' WHERE ORDER_UUID='$order_id';";
            querySql($sql);
        }
    }

    getCommonLogs();
    exit;
}



function run_buy_logic(){
    global $apikey, $apisecret;
    global $default_currency, $target_currency;
    global $default_balance;
    global $current_rate, $buy_rate, $avoid_rate;

    if(/*$current_rate <= $buy_rate &&*/ $current_rate > $avoid_rate) {
        $buy_quantity = getBuyQuantity($buy_rate, $default_balance);
        buyAction($apikey, $apisecret, $default_currency, $target_currency, $buy_quantity, $buy_rate);
    }
}

function run_risk_sell_logic($count_flag){
    global $apikey, $apisecret; 
    global $default_currency, $target_currency; 
    global $target_balance;
    global $current_rate, $sale_rate, $avoid_rate; 
    global $run_risk_count, $alt_app_settings;

    if($current_rate <= $avoid_rate){
        $sell_quantity = getSellQuantity($avoid_rate, $target_balance);
        riskSellAction($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $current_rate);
        if($count_flag){
                $run_risk_count = $run_risk_count + 1;
                $alt_app_settings->others->current_buy_after_risk_count = $run_risk_count;
                echo("RUN_RISK_SELL_LOGIC_COUNT  was increased to :".$run_risk_count."\n");
        }
    }
}

function run_sell_logic(){
    global $apikey, $apisecret;
    global $default_currency, $target_currency; 
    global $target_balance;
    global $current_rate, $sale_rate, $avoid_rate;

    if(/*$current_rate >= $sale_rate &&*/true){
        $sell_quantity = getSellQuantity($sale_rate, $target_balance);
        sellAction($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $sale_rate);
    }
}

if($run_buy_logic && !$stop_buy_after_risk && $buy && !$open_order){
    run_buy_logic();
}

if($run_risk_sell_logic && $sell && !$open_order){
    run_risk_sell_logic(true);
}

if($run_sell_logic && $sell && !$open_order){
    run_sell_logic();
}

$alt_app_settings = json_encode($alt_app_settings, JSON_PRETTY_PRINT);
file_put_contents($file_alt_settings, $alt_app_settings);

getCommonLogs();

?>