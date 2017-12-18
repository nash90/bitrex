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


//init variables 
$target_balance = 0;
$current_rate = 0;
$open_order = false;
$trade_count = 0;
$stop_buy_after_risk = false;


// ALL Function available 

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

//fetch top 50 cryptos by marketcap
function getMarketCap($limit){
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

function getOpenOrder($apikey, $apisecret, $fromCurrency, $toCurrency){
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

    //$order_size = count($obj["result"]);
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
    global $open_order;
    
    $buy_coin = buyCoin($apikey, $apisecret, $default_currency, $target_currency, $buy_quantity, $buy_rate);
    if($buy_coin["success"] == true){
        echo("\n".$buy_coin["result"]["uuid"]." API Buy Success at rate: ".$buy_rate." : ".$default_currency.'-'.$target_currency." : ".$buy_quantity."\n");
    }else{
        echo("\nAPI Buy Failed at rate: ".$buy_rate." : ".$default_currency.'-'.$target_currency." : ".$buy_quantity."\n");
    }
    $open_order = true;
}

function sellAction($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $sale_rate){
    echo("\n######### EXECUTED SELL ACTION ############\n");
    global $open_order;
    
    $sell_coin = sellCoin($apikey, $apisecret, $default_currency, $target_currency, $sell_quantity, $sale_rate);
    if($sell_coin["success"] == true) {
        echo("\n".$sell_coin["result"]["uuid"]." API Sell Success at rate: ".$sale_rate." : ".$default_currency.'-'.$target_currency." : ".$sell_quantity."\n");
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

function log_in_file($file_name, $content){
    file_put_contents($file_name, $content, FILE_APPEND | LOCK_EX);
}

function setAutoRates(){
    global $buy_rate, $sale_rate, $avoid_rate, $current_rate;
    global $alt_app_settings;

    $alt_buy_rate = $current_rate - (($alt_app_settings->rate_percent->buy_percent/100) * $current_rate);
    $buy_rate = $alt_buy_rate;
    $alt_app_settings->alt_rate->alt_buy_rate = $alt_buy_rate;

    $alt_sale_rate = $current_rate + (($alt_app_settings->rate_percent->sale_percent/100) * $current_rate);
    $sale_rate = $alt_sale_rate;
    $alt_app_settings->alt_rate->alt_sale_rate = $alt_sale_rate; 

    $alt_avoid_rate = $current_rate - (($alt_app_settings->rate_percent->avoid_percent/100) * $current_rate);
    $avoid_rate = $alt_avoid_rate;
    $alt_app_settings->alt_rate->alt_avoid_rate = $alt_avoid_rate;

}

//Actions get balance
$remote_balance = bittrexbalance($apikey, $apisecret, $default_currency);
$target_balance = bittrexbalance($apikey, $apisecret, $target_currency);

//Action get current rate
$current_rate = getMarketInfo($default_currency, $target_currency);

//Action get Open order
$open_order_obj = getOpenOrder($apikey, $apisecret, $default_currency, $target_currency);

$open_order_result = ($open_order_obj) ? $open_order_obj["result"] : [];

//bitrex api seems to return old open order status and data sometimes, maybe due to some cache or db replication time
if(count($open_order_result) == 0 ){
    echo "confirming open_order_obj again\n";
    $open_order_obj = getOpenOrder($apikey, $apisecret, $default_currency, $target_currency);
    $open_order_result = ($open_order_obj) ? $open_order_obj["result"] : [];
}

if(count($open_order_obj["result"]) > 0 ){
        $open_order = true; 
    } else {
        $open_order = false;
    }

$buy = false;
$sell = false;

if ($target_balance > 0.0000000099 && !$open_order) {
    $sell = true;
}else {
    if($default_balance > 0.0005 && !$open_order){
        $buy = true;
    }
}

$run_risk_count = (int) $alt_app_settings->others->current_buy_after_risk_count;  
if($run_risk_count >= $stop_buy_after_risk_max_count){
    $stop_buy_after_risk = true;
}

if($auto_rate_selection_flag && !$open_order){
    setAutoRates();

}
//print_r($balance); echo("\n");
//print_r($getMarketInfo);
echo("The rate of coin ".$default_currency.'-'.$target_currency." is : ".$current_rate."\n");
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

print_r(json_encode($open_order_obj, JSON_PRETTY_PRINT));
if($open_order) {
    echo("open order already exists!!! \n");
    //print_r($open_order_obj);

    foreach($open_order_result as $key){
        $order_id = $key["OrderUuid"];
        if($key["OrderType"] == "LIMIT_SELL"){

            if($key["Limit"] > $avoid_rate){
                if($current_rate <= $avoid_rate || $key["Limit"] !== $sale_rate){
                    cancel_open_order($apikey, $apisecret, $order_id);
                }
            }

            if($key["Limit"] <= $avoid_rate){
                if($key["Limit"] < $current_rate){
                    cancel_open_order($apikey, $apisecret, $order_id);
                }
            }
        }

        if($key["OrderType"] == "LIMIT_BUY"){
            if($key["Limit"] !== $buy_rate){
                cancel_open_order($apikey, $apisecret, $order_id);
                echo("Cancelled because Limit did not match current buy rate \n");
            }

            $check_rate = $key["Limit"] + ((($alt_app_settings->rate_percent->sale_percent)/100) * $key["Limit"]);
            if($current_rate > $check_rate){
                cancel_open_order($apikey, $apisecret, $order_id);
                echo("Cancelled because the current rate exceed the check_rate \n");
            }
        }
    }

    echo("\n######### END BOT ############\n");
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

if($run_buy_logic && !$stop_buy_after_risk && $buy){
    run_buy_logic();
}

if($run_risk_sell_logic && $buy){
    run_risk_sell_logic(true);
}

if($run_sell_logic && $sell){
    run_sell_logic();
}

$alt_app_settings = json_encode($alt_app_settings, JSON_PRETTY_PRINT);
file_put_contents($file_alt_settings, $alt_app_settings);
echo("\n######### END BOT ############\n");

?>