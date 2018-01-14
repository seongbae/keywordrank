<?php 
namespace seongbae\KeywordRank\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Storage;
use seongbae\KeywordRank\Models\Website;
use seongbae\KeywordRank\Models\Keyword;
use seongbae\KeywordRank\Models\Ranking;

class KeywordRankFetcher
{
	protected $config;

	public function __construct($config)
	{
		$this->config = $config;
	}

    public function addWebsite($url, $name, $userId) 
    {
        $website = new Website();
        $website->url = $url;
        $website->name = $name;
        $website->user_id = $userId;
        $website->save();

        return $website;
    }

	public function addKeywords($websiteId, $keywords, $userId) 
	{
		foreach ($keywords as $keyword)
		{
			$this->addKeyword($websiteId, $keyword, $userId);
		}
	}

	public function addKeyword($websiteId, $keyword, $userId)
	{
        $kw = new Keyword();
        $kw->website_id = $websiteId;
        $kw->keyword = $keyword;
        $kw->user_id = $userId;
        $kw->save();

        return $kw;
	}

    public function addRanking($keywordId, $rank, $rankDate)
    {
        $rk = new Ranking();
        $rk->keyword_id = $keywordId;
        $rk->rank = $rank;
        $rk->rank_date = $rankDate;
        $rk->save();

        return $rk;
    }

	public function fetchAll($url="")
	{
		// To do: fetch position for all keywords for a given URL in the database
	}

	public function getPosition($url, $keyword, $nocache=false) {
		
		if ($url != null && $keyword != null) {
        	$proxy_url = $this->config['proxy_url']; 
        	$proxy_uid = $this->config['proxy_uid'];
            $proxy_pwd = $this->config['proxy_pwd'];            
            $portal = $this->config['portal'];
            $country = $this->config['country']; 
            $language = $this->config['language'];     
            $max_pages = $this->config['max_pages'];
            $use_cache = $this->config['use_cache']; 
            $one_hundred_results = $this->config['one_hundred_results'];
            $load_all_ranks = $this->config['load_all_ranks'];
            $filter = $this->config['filter'];
            $working_dir=storage_path($this->config['cache_path']);
            
            $PROXY=array();    
            $LICENSE=array();                                         
            $results=array();
            $info=array();
            $proxy=array();
            $proxy_res=array();
            $serp_data=array();
                    
            $max_errors_page=5; 
            $rotate_ip=1;
            $page=0;
            $rank=0;
            $ip_usage_data=null;

            $search_string=urlencode($keyword);
            
            if(!File::exists($working_dir)) {
                Storage::makeDirectory($working_dir);
            }
            
            if (!rmkdir($working_dir)) 
                return;

            $country_data=get_google_cc($country,$language, $proxy_uid, $proxy_pwd, $proxy_url);
            
            if (!$country_data) {
                Log::info("Invalid country/language code specified.");
                return;
            }

            $license=get_license($proxy_url, $proxy_uid, $proxy_pwd);

            if (!$license || $license['active']==0) {
                Log::info("The specified license ($proxy_uid) is not active.");
                return;
            }

            if ($license['protocol'] != "http") {
                Log::info("The seo-proxies.com proxy protocol of license $proxy_uid is not set to HTTP, please change the protocol to HTTP.");
                return;
            }

            /*
            * This loop iterates through all result pages for the given keyword
            */
            for ($page=0;$page<$max_pages;$page++)
            {
                Log::info('looping through pages...');

                if (!$nocache)
                	$serp_data=load_cache($search_string,$page,$country_data,$use_cache, $one_hundred_results, $working_dir); // load results from local cache if available for today
                
                if (!$serp_data) 
                {
                    $ip_usage_result=check_ip_usage($proxy, $working_dir, null); // test if ip has not been used within the critical time
                    $ip_usage_data = $ip_usage_result['ip_usage_data'];
                    
                    while ($ip_usage_result['status'] > 0 || $rotate_ip)
                    {
                        $proxy_result=rotate_proxy($proxy_uid, $proxy_pwd, $proxy_url); // start/rotate to the IP that has not been started for the longest time, also tests if proxy connection is working

                        if ($proxy_result['status'] != 1)
                            Log::info("Fatal error: proxy rotation failed.");

                        $ip_usage_result_2=check_ip_usage($proxy_result['proxy'], $working_dir, $ip_usage_data); // test if ip has not been used within the critical time
                        $ip_usage_data = $ip_usage_result_2['ip_usage_data'];

                        if ($ip_usage_result_2['status'] <= 0) 
                            Log::info("ERROR: No fresh IPs left, try again later.");
                        else 
                        {
                            $rotate_ip=0; // ip rotated
                            break; // continue
                        }
                    }   
                    
                    delay_time($license['total_ips']); // stop scraping based on the license size to spread scrapes best possible and avoid detection
                    
                    $scrape_result=scrape_serp_google($search_string,$page,$country_data, $one_hundred_results, $proxy_result['ch'], $filter); // scrape html from search engine
                    
                    if ($scrape_result['status'] == "SCRAPE_SUCCESS")
                    {
                        Log::info("SCRAPE SUCCESS");

                        mark_ip_usage($proxy_result['proxy'], $working_dir, $ip_usage_data); // store IP usage, this is very important to avoid detection and gray/blacklistings
                    
                        $serp_data=process_raw_v2($scrape_result['htmldata'], $page, $one_hundred_results, $working_dir); // process the html and put results into $serp_data
                        $results = $serp_data['results'];

                        if (($serp_data['status'] == "PROCESS_SUCCESS_MORE") || ($serp_data['status'] == "PROCESS_SUCCESS_LAST"))
                        {
                            $result_count=count($results);
                            $results['page']=$page;
                            if ($serp_data['status'] != "PROCESS_SUCCESS_LAST")
                                $results['lastpage']=1;
                            else
                                $results['lastpage']=0;
                            $results['keyword']=$keyword;
                            $results['cc']=$country_data['cc'];
                            $results['lc']=$country_data['lc'];
                            $results['result_count']=$result_count;
                            
                            store_cache($results,$search_string,$page,$country_data, $working_dir, $one_hundred_results); // store results into local cache   
                        } 
                        
                        if ($serp_data['status'] != "PROCESS_SUCCESS_MORE")
                            break; // last page

                        if (!$load_all_ranks)
                        {
                            for ($n=0;$n < $result_count;$n++)
                            if (strstr($results[$n]['url'],$url))
                            {
                                verbose("Located $url within search results.");
                                break;
                            }
                        }

                    } else  {
                        Log::info("There was an issue with scrape");

                        if ($max_errors_page--)
                        {
                            $page--; //There was an error scraping (Code: $scrape_result), trying again .. ";
                            continue;
                        } 
                        else
                        {
                            $page--;
                            if ($max_errors_total--)
                            {
                                Log::info("Too many errors scraping keyword $search_string (at page $page). Skipping remaining pages of keyword $search_string .. ");
                                break; 
                            } 
                            else
                            {
                                Log::info("ERROR: Max keyword errors reached, something is going wrong.");
                            }
                            break;
                        }
                    }
                } else // scrape clause
                {
                    $results = $serp_data;
                }

                $result_count=$results['result_count'];
                
                for ($ref=0;$ref<$result_count;$ref++)
                {
                    $rank++;
                    $rank_data[$keyword][$rank]['title']=$results[$ref]['title'];
                    $rank_data[$keyword][$rank]['url']=$results[$ref]['url'];
                    $rank_data[$keyword][$rank]['host']=$results[$ref]['host'];
                    if (strstr($rank_data[$keyword][$rank]['url'],$url))
                    {
                        
                        $info['rank']=$rank;
                        $info['url']=$rank_data[$keyword][$rank]['url'];
                        $siterank_data[$keyword][]=$info;
                        break;
                    }
                }
                
            } 
        }

        if (array_key_exists('rank', $info))
            return $info['rank'];
        else
            return 0;
        
    }
	
}