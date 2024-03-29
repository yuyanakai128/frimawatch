<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\User;
use App\Models\TimeLine;
use App\Models\Setting;
use App\Models\Notification;
use App\Models\NotificationService;
use DB;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;
use Facebook\WebDriver\WebDriverCheckboxes;
use Facebook\WebDriver\WebDriverRadios;
use Facebook\WebDriver\WebDriverSelect;
use Symfony\Component\DomCrawler\Crawler;

use Goutte\Client;
use Symfony\Component\HttpClient\HttpClient;
use Illuminate\Support\Facades\Http;

class SendNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send:email';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    public const SENT_COUNT = 30;
    protected $count = 1;
    protected $lower_price;
    protected $upper_price;
    protected $keyword;
    protected $excluded_word;
    protected $user;
    protected $results = [];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info("start");

        $availableUsers = User::where('is_admin',0)->where('active',1)->get();
        foreach($availableUsers as $user) {
            $this->user = $user;
            $notifications = $user->notifications;

            if($user->mailSent >= $user->mailLimit) {
                $this->info("mail limited");
                continue;
            }
            if($user->mailStatus == "off") {
                $this->info("mail notification off");
                continue;
            }
            foreach($notifications as $notification) {
                $this->count = 0;
                $this->results = [];
                $keyword = $notification->keyword;
                $this->keyword = $notification->keyword;
                $this->lower_price = $notification->lower_price;
                $this->upper_price = $notification->upper_price;
                $this->excluded_word = $notification->excluded_words;
                $services = $notification->services;
                $status = $notification->status;

                foreach($services as $service) {
                    
                    if($this->count > self::SENT_COUNT) break;
                    if (str_contains($service, 'wowma')) {
                        $client = new Client();
                        $pages = 0;
                        for ($i = 0; $i < $pages + 1; $i++) {
                            if($this->count > self::SENT_COUNT) break;
                            if($i == 0 ){
                                $url = "https://auction.brandear.jp/search/list/?SearchFullText=".$keyword;
                                $client = new Client(HttpClient::create([
                                    'timeout'         => 20,
                                    'headers' => [
                                        'Accept' => '*/*',
                                        'Host' => 'auction.brandear.jp',
                                        'Postman-Token' => '',
                                        'Cookie' => 'ba_defacto_analytics=%5B%5D; ba_search_history_entire=K%B42%B4%AA%CE%B42%B0N%B42%B2%AA.%B62%B1R%CAN%AD%2CV%02%F2%A1%12%C5V%86%40%C1%E0%D4%C4%A2%E4%0C%B7%D2%9C%9C%90%D4%8A%12%25%EB%DAb%2B3%2B%A5%B2%C4%9C%D2TT%C5%96VJ%8F%9B%B6%3Fn%5E%FC%B8%B9%05%A8%AC%B6%16%00; ba_sessid=6cfaca6f01c7292770b4a341000ffb32',
                                        ]
                                    ]));
                                $client->setServerParameter('HTTP_USER_AGENT', 'user agent');
                                $crawler = $client->request('GET',$url);
                                try {
                                    $pages = $crawler->filter('.resultCount span')->text()
                                    ? (intval($crawler->filter('.resultCount span')->text() / 50) + 1)
                                    : 0
                                ;
                                }catch(\Throwable  $e){
                                    $pages = 0;break;
                                }
                            }else {
                                $url = "https://auction.brandear.jp/search/list/?SearchFullText=".$keyword."&ItemOrder=0&page=".$i;
                                $client = new Client(HttpClient::create([
                                    'timeout'         => 20,
                                    'headers' => [
                                        'Accept' => '*/*',
                                        'Host' => 'auction.brandear.jp',
                                        'Postman-Token' => '',
                                        'Cookie' => 'ba_defacto_analytics=%5B%5D; ba_search_history_entire=K%B42%B4%AA%CE%B42%B0N%B42%B2%AA.%B62%B1R%CAN%AD%2CV%02%F2%A1%12%C5V%86%40%C1%E0%D4%C4%A2%E4%0C%B7%D2%9C%9C%90%D4%8A%12%25%EB%DAb%2B3%2B%A5%B2%C4%9C%D2TT%C5%96VJ%8F%9B%B6%3Fn%5E%FC%B8%B9%05%A8%AC%B6%16%00; ba_sessid=6cfaca6f01c7292770b4a341000ffb32',
                                        ]
                                    ]));
                                $crawler = $client->request('GET', $url);
                            }
                            try {
                                $crawler->filter('#result li')->each(function ($node) {
                                    if($this->count > self::SENT_COUNT) return false;
                                    $url = $node->filter('.item_name a')->attr('href');
                                    $itemImageUrl = $node->filter('.item .img img')->attr('data-original');
                                    $currentPrice = intval(preg_replace('/[^0-9]+/', '', $node->filter('span.price')->text()), 10);
                                    $itemName   = $node->filter('.item_name span')->text();
                                    if($this->compareCondition($this->lower_price, $this->upper_price,$this->excluded_word, $currentPrice, $itemName )){
                                        array_push($this->results, [
                                            'currentPrice' => $currentPrice,
                                            'itemImageUrl' => $itemImageUrl,
                                            'itemName' => $itemName,
                                            'url' => 'https://auction.brandear.jp'.$url,
                                            'service' => 'brandear',
                                        ]);
                                        $this->count++;
                                    }
                                });
                            }catch(\Throwable  $e){
                                continue;
                            }
                        }
                    }

                    if (str_contains($service, '2ndstreet')) {

                        if($this->count > self::SENT_COUNT) break;
                        $this->initBrowser();
                        $this->results = [];
        
                        $url = "https://www.2ndstreet.jp/search?keyword=".$keyword."&page=0";
        
                        if(isset($this->lower_price)){
                            $url .= '&minPrice='.$this->lower_price;
                        }
                        
                        if(isset($this->upper_price)){
                            $url .= '&maxPrice='.$this->upper_price;
                        }
        
                        $url .= '&search=OK';
        
                        
                        $response = $this->driver->get($url);
        
                        $crawler = new Crawler($response->getPageSource());
        
                        $this->driver->close();
                        
                        try {
                            $crawler->filter('.js-favorite')->each(function ($node) {
                                if($this->count > self::SENT_COUNT) return false;
                                $url = $node->filter('a.itemCard_inner')->attr('href');
                                $itemImageUrl = $node->filter('.itemCard_img img')->attr('src');
                                $currentPrice = intval(preg_replace('/[^0-9]+/', '', $node->filter('.itemCard_price')->text()), 10);
                                $itemName   = $node->filter('.itemCard_name')->text();
        
                                if($this->compareCondition($this->lower_price, $this->upper_price,$this->excluded_word, $currentPrice, $itemName )){
                                    array_push($this->results, [
                                        'currentPrice' => $currentPrice,
                                        'itemImageUrl' => $itemImageUrl,
                                        'itemName' => $itemName,
                                        'url' => 'https://www.2ndstreet.jp'.$url,
                                        'service' => '2ndstreet',
                                    ]);
                                    $this->count++;
                                }
                            });
                        }catch(\Throwable  $e){
                            
                        }
                    }

                    if (str_contains($service, 'komehyo')) {
                        $client = new Client();
                        $pages = 1;
                        // $new = mb_convert_encoding($keyword, "SJIS", "UTF-8");
                        for ($i = 1; $i < $pages + 1; $i++) {
                            if($this->count > self::SENT_COUNT) break;
                            if($i == 1 ){
                                $url = "https://komehyo.jp/search/?q=".$keyword."&page=1";
                                $crawler = $client->request('GET', $url);
                                try {
                                    $pages = ($crawler->filter('.p-pager li')->count() > 0)
                                    ? $crawler->filter('.p-pager li:nth-last-child(2)')->text()
                                    : 0
                                ;
                                if($pages == 0) break;
                                }catch(\Throwable  $e){
                                    $pages = 1;break;
                                }
                                
                            }else {
                                $url = "https://komehyo.jp/search/?q=".$keyword."&page=".$i;
                                $crawler = $client->request('GET', $url);
                            }
                            try {
                                
                                $crawler->filter('.p-lists__item')->each(function ($node) {
                                    if($this->count > self::SENT_COUNT) return false;
                                    $url = $node->filter('a.p-link')->attr('href');
                                    $itemStatus = $node->filter('.p-link__label')->text();
                                    $isStatus = false;
                                    if(isset($status)) {
                                        if($status == $itemStatus) {
                                            $isStatus = true;
                                        }else{
                                            $isStatus = false;
                                        }
                                    }else {
                                        $isStatus = true;
                                    }
                                    if($isStatus) {
                                        $itemImageUrl = $node->filter('.p-link__head img')->attr('src');
                                        $currentPrice = intval(preg_replace('/[^0-9]+/', '', $node->filter('.p-link__txt--price')->text()), 10);
                                        $itemName   = $node->filter('.p-link__txt--productsname')->text();
                                        if($this->compareCondition($this->lower_price, $this->upper_price,$this->excluded_word, $currentPrice, $itemName )){
                                            array_push($this->results, [
                                                'currentPrice' => $currentPrice,
                                                'itemImageUrl' => $itemImageUrl,
                                                'itemName' => $itemName,
                                                'url' => 'https://komehyo.jp'.$url,
                                                'service' => 'komehyo',
                                            ]);
                                            $this->count++;
                                        }
                                    }
                                });
                            }catch(\Throwable  $e){
                                continue;
                            }
                            
                        }
                    }

                    if (str_contains($service, 'mercari')) {
                        if($this->count > self::SENT_COUNT) break;
                        $this->initBrowser();
                        $this->results = [];
        
                        $url = "https://jp.mercari.com/search?keyword=".$this->keyword;
                        if(isset($this->lower_price)){
                            $url .= '&price_min='.$this->lower_price;
                        }
                        $url .= '&status=on_sale';
                        if(isset($this->upper_price)){
                            $url .= '&price_max='.$this->upper_price;
                        }
                        if(isset($this->upper_price)){
                            $url .= '&price_max='.$this->upper_price;
                        }
                        $crawler = $this->getPageHTMLUsingBrowser($url);
        
                        $this->driver->close();
        
                        try {
                            $crawler->filter('#item-grid li')->each(function ($node) {
                                if($this->count > self::SENT_COUNT) return false;
                                $url = $node->filter('a')->attr('href');
                                $itemImageUrl = $node->filter('source img')->attr('src');
                                $itemName   = $node->filter('source img')->attr('alt');
                                $itemName = str_replace("のサムネイル","",$itemName);
                                $price = intval(preg_replace('/[^0-9]+/', '', $node->filter('figure')->text()), 10);
                                if($this->compareWords($this->excluded_word, $itemName )){
                                    array_push($this->results, [
                                        'currentPrice' => $price,
                                        'itemImageUrl' => $itemImageUrl,
                                        'itemName' => $itemName,
                                        'url' => 'https://jp.mercari.com'.$url,
                                        'service' => 'mercari',
                                    ]);
                                }
                                $this->count++;
                            });
                        }catch(\Throwable  $e){
                            
                        }
                 
                    }

                    if (str_contains($service, 'yahooflat')) {
                        $client = new Client();
                        $pages = 1;
                        // $new = mb_convert_encoding($keyword, "SJIS", "UTF-8");
                        for ($i = 1; $i < $pages + 1; $i++) {
                            if($this->count > self::SENT_COUNT) break;
                            if($i == 1 ){
                                $url = "https://auctions.yahoo.co.jp/search/search?p=".$keyword."&va=".$keyword."&fixed=1&exflg=1&b=1&n=50";//ヤフオク（定額）
                                $totalUrl = "https://auctions.yahoo.co.jp/search/search?p=".$keyword."&va=".$keyword."&fixed=3&exflg=1&b=1&n=50";
                                $totalcrawler = $client->request('GET', $totalUrl);
                                $flatcount = intval(preg_replace('/[^0-9]+/', '', $totalcrawler->filter('.Tab__items li:nth-last-child(1) .Tab__subText')->text()), 10);
                                if($flatcount == 0) break;
                                $crawler = $client->request('GET', $url);
                                try {
                                    $pages = ($crawler->filter('.Pager__lists li')->count() > 0)
                                    ? $crawler->filter('.Pager__lists li:nth-last-child(3)')->text()
                                    : 0
                                ;
                                if($pages == 0) break;
                                }catch(\Throwable  $e){
                                    $pages = 1;break;
                                }
                                
                            }else {
                                $n = ($i - 1) * 50;
                                $url = "https://auctions.yahoo.co.jp/search/search?p=".$keyword."&va=".$keyword."&fixed=1&exflg=1&b=".(string)$n."&n=50";
                                $crawler = $client->request('GET', $url);
                            }
                            try {
                                $crawler->filter('.Product')->each(function ($node) {
                                    if($this->count > self::SENT_COUNT) return false;
                                    $url = $node->filter('a.Product__imageLink')->attr('href');
                                    $itemImageUrl = $node->filter('.Product__imageData')->attr('src');
                                    $currentPrice = intval(preg_replace('/[^0-9]+/', '', $node->filter('.Product__priceValue')->text()), 10);
                                    $itemName   = $node->filter('.Product__title')->text();
                                    if($this->compareCondition($this->lower_price, $this->upper_price,$this->excluded_word, $currentPrice, $itemName )){
                                        array_push($this->results, [
                                            'currentPrice' => $currentPrice,
                                            'itemImageUrl' => $itemImageUrl,
                                            'itemName' => $itemName,
                                            'url' => $url,
                                            'service' => 'ヤフオク（定額）',
                                        ]);
                                        $this->count++;
                                    }
                                });
                                
                            }catch(\Throwable  $e){
                                continue;
                            }
                            
                        }
                    }

                    if (str_contains($service, 'auction')) {
                        $client = new Client();
                        $pages = 1;
                        // $new = mb_convert_encoding($keyword, "SJIS", "UTF-8");
                        for ($i = 1; $i < $pages + 1; $i++) {
                            if($this->count > self::SENT_COUNT) break;
                            if($i == 1 ){
                                $url = "https://auctions.yahoo.co.jp/search/search?p=".$keyword."&va=".$keyword."&fixed=2&exflg=1&b=1&n=50";//ヤフオク（定額）
                                
                                $crawler = $client->request('GET', $url);
                                try {
                                    $pages = ($crawler->filter('.Pager__lists li')->count() > 0)
                                    ? $crawler->filter('.Pager__lists li:nth-last-child(3)')->text()
                                    : 0
                                ;
                                }catch(\Throwable  $e){
                                    $pages = 1;break;
                                }
                                
                            }else {
                                $n = ($i - 1) * 50;
                                $url = "https://auctions.yahoo.co.jp/search/search?p=".$keyword."&va=".$keyword."&fixed=2&exflg=1&b=".(string)$n."&n=50";
                                $crawler = $client->request('GET', $url);
                            }
                            try {
                                $crawler->filter('.Product')->each(function ($node) {
                                    if($this->count > self::SENT_COUNT) return false;
                                    $url = $node->filter('a.Product__imageLink')->attr('href');
                                    $itemImageUrl = $node->filter('.Product__imageData')->attr('src');
                                    $currentPrice = intval(preg_replace('/[^0-9]+/', '', $node->filter('.Product__priceValue')->text()), 10);
                                    $itemName   = $node->filter('.Product__title')->text();
                                    if($this->compareCondition($this->lower_price, $this->upper_price,$this->excluded_word, $currentPrice, $itemName )){
                                        $productTime  = $node->filter('.Product__time')->text();
                                        if($this->productTimeCompare($productTime)){
                                            array_push($this->results, [
                                                'currentPrice' => $currentPrice,
                                                'itemImageUrl' => $itemImageUrl,
                                                'itemName' => $itemName,
                                                'url' => $url,
                                                'service' => 'ヤフオク（オークション）',
                                            ]);
                                            $this->count++;
                                        }
                                    }
                                });
                                if($pages == 0) break;
                                
                            }catch(\Throwable  $e){
                                continue;
                            }
                            
                        }
                    }

                    if (str_contains($service, 'netmall')) {
                        $client = new Client();
                        $pages = 1;
                        // $new = mb_convert_encoding($keyword, "SJIS", "UTF-8");
                        for ($i = 1; $i < $pages + 1; $i++) {
                            if($this->count > self::SENT_COUNT) break;
                            if($i == 1 ){
                                $url = "https://netmall.hardoff.co.jp/search/?exso=1&q=".$keyword."&s=7";
                                
                                $crawler = $client->request('GET', $url);
                                try {
                                    $pages = ($crawler->filter('.pagenation a')->count() > 0)
                                    ? $crawler->filter('.pagenation a:nth-last-child(2)')->text()
                                    : 0
                                ;
                                if($pages == 0) break;
                                }catch(\Throwable  $e){
                                    $pages = 1;break;
                                }
                                
                            }else {
                                $url = "https://netmall.hardoff.co.jp/search/?p=2&q=".$keyword."&exso=1&s=7";
                                $crawler = $client->request('GET', $url);
                            }
                            try {
                                $crawler->filter('.itemcolmn_item')->each(function ($node) {
                                    if($this->count > self::SENT_COUNT) return false;
                                    $url = $node->filter('.itemcolmn_item a')->attr('href');
                                    $itemImageUrl = $node->filter('.item-img-square img')->attr('src');
                                    $currentPrice = intval(preg_replace('/[^0-9]+/', '', $node->filter('.item-price-en')->text()), 10);
                                    $itemName   = $node->filter('.item-brand-name')->text();
                                    if($this->compareCondition($this->lower_price, $this->upper_price,$this->excluded_word, $currentPrice, $itemName )){
                                        array_push($this->results, [
                                            'currentPrice' => $currentPrice,
                                            'itemImageUrl' => $itemImageUrl,
                                            'itemName' => $itemName,
                                            'url' => $url,
                                            'service' => '中古通販のオフモール',
                                        ]);
                                        $this->count++;
                                    }
                                });
                                
                            }catch(\Throwable  $e){
                                continue;
                            }
                            
                        }
                    }

                }

                $this->sendEmail($this->results, $this->user);
            }
        }
        $this->info("end");
        return 0;
    }

    /**
     * Get page using browser.
     */
    public function getPageHTMLUsingBrowser(string $url)
    {
        $response = $this->driver->get($url);

        $this->driver->wait(5000,1000)->until(
            function () {
                $elements = $this->driver->findElements(WebDriverBy::XPath("//div[contains(@id,'search-result')]"));
                sleep(3);
                return count($elements) > 0;
            },
        );
        
        return new Crawler($response->getPageSource(), $url);
    }
    /**
     * Init browser.
     */
    public function initBrowser()
    {
        $options = new ChromeOptions();
        $arguments = ['--disable-gpu', '--no-sandbox', '--disable-images', '--headless'];

        $options->addArguments($arguments);

        $caps = DesiredCapabilities::chrome();
        $caps->setCapability('acceptSslCerts', false);
        $caps->setCapability(ChromeOptions::CAPABILITY, $options);
        
        $this->driver = RemoteWebDriver::create('http://localhost:4444', $caps);
    }

    public function productTimeCompare($productTime){
        if(str_contains($productTime, '日')){
            $time = intval(preg_replace('/[^0-9]+/', '', $productTime), 10);
            if($time == 1) {
                return true;
            }
            else {
                return false;
            }
        }else{
            return true;
        }
    }

    public function compareWords($excluded_word, $itemName) {
        $result = true;
        if(isset($excluded_word)) {
            $words = explode(' ',$excluded_word);
            foreach($words as $word) {
                if($word == "") continue;
                if(str_contains($itemName, $word))$result = false;
            }
        }
        return $result;
    }

    public function compareCondition($lower_price, $upper_price,$excluded_word, $currentPrice, $itemName ) {
        $result = false;
        $result = $lower_price ? ($currentPrice >= $lower_price) : true;
        if($result) {
            $result = $upper_price ? ($currentPrice <= $upper_price) : true;
            if($result) {
                if(isset($excluded_word)) {
                    $words = explode(' ',$excluded_word);
                    foreach($words as $word) {
                        if($word == "") continue;
                        if(str_contains($itemName, $word))$result = false;
                    }
                }
            }
        }
        return $result;
    }

    public function sendEmail($results, $user) {

        $items = $results;
        $items = array_unique($items,SORT_REGULAR);
        $mailLimit = $user->mailLimit;
        
        $urls = TimeLine::where('user_id',$user->id)->get();
        foreach($urls as $url) {
            foreach($results as $key => $result) {
                if($url->url == $result['url']) {
                    unset($items[$key]);break;
                }
            }
        }
        $content = $user->name."様 商品があります。". PHP_EOL .PHP_EOL;
        
        if(count($items) > 0) {
            
            foreach($items as $item) {
                
                $content .= "商品名　".$item['itemName']. PHP_EOL ."商品価格　".number_format($item['currentPrice'])."円". PHP_EOL ."商品サービス　".$item['service']. PHP_EOL ."商品ページ ".$item['url']. PHP_EOL;
                if(isset($this->keyword)) {
                    $content .= "キーワード : " .$this->keyword. PHP_EOL . PHP_EOL . PHP_EOL;
                }
            }
            $email = $user->email;
            // $user_id = 'trialphoenix';
            // $api_key = '2aUSJ6gntGT6paez6XPaihMc0XEXZDWJqbwIVbRmpSWXwsDCKGjUZRDjfMIjt4Hw';
            $user_id = 'phoenix';
            $api_key = 'lTvBUaSpw6ZsG5erwfjiAcTpxOw3zM7t4jhqSBNa0D7hll5njQwKsMj1abBVt1cK';
            \Blastengine\Client::initialize($user_id, $api_key);
            $transaction = new \Blastengine\Transaction();
            $transaction
                ->to($email)
                ->from("devlife128@gmail.com")
                ->subject('商品があります。')
                ->text_part($content);
            try {
                $transaction->send();
            } catch ( Exception $ex ) {
                // Error
            }
            
            // 結果の出力
            $this->info("sent");

            DB::beginTransaction();
            try {
                $inserted_data = [];
                foreach($items as $item) {
                    array_push($inserted_data,[
                        'user_id' => $user->id,
                        'itemName' => $item['itemName'],
                        'keyword' => $this->keyword,
                        'itemImageUrl' => $item['itemImageUrl'],
                        'currentPrice' => $item['currentPrice'],
                        'url' => $item['url'],
                        'service' => $item['service'],
                        "created_at" =>  \Carbon\Carbon::now(), # new \Datetime()
                        "updated_at" => \Carbon\Carbon::now(),  # new \Datetime()
                    ]) ;
                }
                
                TimeLine::lockForUpdate()->insert($inserted_data);
                $availableUser = User::where('id',$user->id)->lockForUpdate()->first();
                $mailSent = $availableUser->mailSent;
                User::where('id',$user->id)->lockForUpdate()->update(array('mailSent' => $mailSent + 1));

                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                
            }
            
        } else {
            $this->info("There are no matching items");
        }
        
    }
}
