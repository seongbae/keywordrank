<?php 

/* License: open source for private and commercial use This code is free to use and modify as long as this comment stays untouched on top. URL of original source: http://google-rank-checker.squabbel.com Author of original source: justone@squabbel.com This tool should be completely legal but in any case you may not sue or seek compensation from the original Author for any damages or legal issues the use may cause. By using this source code you agree NOT to increase the request rates beyond the IP management function limitations, this would only harm our common cause. */ 

if (!function_exists('verbose')) {
	function verbose($text) { 
		Log::info($text); 
	} 
}
	


/* * By default (no force) the function will load cached data within 24 hours otherwise reject the cache. * Google does not change its ranking too frequently, that 's why 24 hours has been chosen.
 *
 * Multithreading: When multithreading you need to work on a proper locking mechanism
 */
if (!function_exists('load_cache')) {
	function load_cache($search_string,$page,$country_data,$use_cache, $one_hundred_results, $working_dir)
	{
		if ($use_cache) 
		{
			$lc=$country_data['lc'];
			$cc=$country_data['cc'];

			if ($one_hundred_results)
				$hash=md5($search_string."_".$lc."_".$cc.".".$page.".100p");
			else
				$hash=md5($search_string."_".$lc."_".$cc.".".$page);

			$file=$working_dir."/".$hash.".cache";

			$now=time();

			if (file_exists($file))
			{
				$ut=filemtime($file);
				$dif=$now-$ut;
				$hour=(int)($dif/(60*60));
				if ($dif < (60*60*24))
				{
					$serdata=file_get_contents($file);
					$serp_data=unserialize($serdata);
					verbose("Cache: loaded file $file for $search_string and page $page. File age: $hour hours");
					return $serp_data;
				}
				return NULL;
			} else
				return NULL;

		} else {
			return NULL;
		}
	}
}

/*
 * Multithreading: When multithreading you need to work on a proper locking mechanism
 */
if (!function_exists('store_cache')) {
	function store_cache($serp_data,$search_string,$page,$country_data, $working_dir, $one_hundred_results)
	{
		$lc=$country_data['lc'];
		$cc=$country_data['cc'];
		
		if ($one_hundred_results)
			$hash=md5($search_string."_".$lc."_".$cc.".".$page.".100p");
		else
			$hash=md5($search_string."_".$lc."_".$cc.".".$page);
		
		$file=$working_dir."/".$hash.".cache";
		$now=time();

		if (file_exists($file))
		{
			$ut=filemtime($file);
			$dif=$now-$ut;
			if ($dif < (60*60*24)) 
				verbose("Warning: cache storage initated for $search_string page $page which was already cached within the past 24 hours!");
		}

		$serdata=serialize($serp_data);
		file_put_contents($file,$serdata, LOCK_EX);
		verbose("Cache: stored file $file for $search_string and page $page.");
	}
}

// check_ip_usage() must be called before first use of mark_ip_usage()
if (!function_exists('check_ip_usage')) {
	function check_ip_usage($PROXY, $working_dir, $ip_usage_data)
	{
		$status = -1;
		
		if (!isset($PROXY['ready']) || !$PROXY['ready']) 
			return ['status'=>0,'ip_usage_data'=>null]; // proxy not ready/started
		
		if (!isset($ip_usage_data))
		{
			if (!file_exists($working_dir."/ipdata.obj")) // usage data object as file
			{
				verbose("Warning!"."The ipdata.obj file was not found, if this is the first usage of the rank checker everything is alright."."Otherwise removal or failure to access the ip usage data will lead to damage of the IP quality.");
				sleep(5);
				$ip_usage_data=array();
			} else
			{
				$ser_data=file_get_contents($working_dir."/ipdata.obj");
				$ip_usage_data=unserialize($ser_data);
			}
		}
		
		if (!isset($ip_usage_data[$PROXY['external_ip']])) 
		{
			verbose('IP '.$PROXY['external_ip'].' is ready for use ');

			return ['status'=>1,'ip_usage_data'=>$ip_usage_data];
			// the IP was not used yet
		}

		if (!isset($ip_usage_data[$PROXY['external_ip']]['requests'][20]['ut_google'])) 
		{
			verbose("IP $PROXY[external_ip] is ready for use");

			return ['status'=>1,'ip_usage_data'=>$ip_usage_data];
			 // the IP has not been used 20+ times yet, return true
		}
		
		$ut_last=(int)$ip_usage_data[$PROXY['external_ip']]['ut_last-usage']; // last time this IP was used
		$req_total=(int)$ip_usage_data[$PROXY['external_ip']]['request-total']; // total number of requests made by this IP
		$req_20=(int)$ip_usage_data[$PROXY['external_ip']]['requests'][20]['ut_google']; // the 20th request (if IP was used 20+ times) unixtime stamp
		
		$now=time();
		if (($now - $req_20) > (60*60) ) 
		{
			verbose("IP $PROXY[external_ip] is ready for use");
			$status= 1; // more than an hour passed since 20th usage of this IP
		} else
		{
			$cd_sec=(60*60) - ($now - $req_20);
			verbose("IP $PROXY[external_ip] needs $cd_sec seconds cooldown, not ready for use yet");
			$status= 0; // the IP is overused, it can not be used for scraping without being detected by the search engine yet
		}
		
		return ['status'=>$status,'ip_usage_data'=>$ip_usage_data];
	}
}


// return 1 if license is ready, otherwise 0
if (!function_exists('get_license')) {
	function get_license($portal, $uid, $pwd)
	{
		$res=proxy_api("hello",$uid, $pwd, $portal); // will fill $LICENSE
		//print_r($res);
		$license = $res['license'];
		$ip="";
		
		if ($res['status'] <= 0)
		{
			verbose("API error: Proxy API connection failed. trying again soon.");
		} 
		else
		{
			($license['active']==1) ? $ready="active" : $ready="not active";
			verbose("API success: License is $ready.");
		}
		
		return $license;
	}
}

/* Delay (sleep) based on the license size to allow optimal scraping
 *
 * Warning!
 * Do NOT change the delay to be shorter than the specified delay.
 * When scraping Google you should never do more than 20 requests per hour per IP address
 * This function will create a delay based on your total IP addresses.
 *
 * Together with the IP management functions this will ensure that your IPs stay healthy (no wrong rankings) and undetected (no virus warnings, blacklists, captchas)
 *
 * Multithreading:
 * When multithreading you need to multiply the delay time ($d) by the number of threads
 */
if (!function_exists('delay_time')) {
	function delay_time($total_ips)
	{	
		$d=(3600*1000000/(((float)$total_ips)*12));
		verbose("Delay based on license size, please wait.");
		usleep(10000000);
	}
}

/*
 * Updates and stores the ip usage data object
 * Marks an IP as used and re-sorts the access array 
 */
if (!function_exists('mark_ip_usage')) {
	function mark_ip_usage($proxy, $working_dir, $ip_usage_data)
	{
		if (!isset($ip_usage_data)) {
			verbose("ERROR: Incorrect usage. check_ip_usage() needs to be called once before mark_ip_usage()!");
			return NULL;
		}
		
		$now=time();
		
		$ip_usage_data[$proxy['external_ip']]['ut_last-usage']=$now; // last time this IP was used
		if (!isset($ip_usage_data[$proxy['external_ip']]['request-total'])) $ip_usage_data[$proxy['external_ip']]['request-total']=0;
		$ip_usage_data[$proxy['external_ip']]['request-total']++; // total number of requests made by this IP
		// shift fifo queue
		for ($req=19;$req>=1;$req--)
		{
			if (isset($ip_usage_data[$proxy['external_ip']]['requests'][$req]['ut_google']))
			{
				$ip_usage_data[$proxy['external_ip']]['requests'][$req+1]['ut_google']=$ip_usage_data[$proxy['external_ip']]['requests'][$req]['ut_google']; 
			}
		}
		$ip_usage_data[$proxy['external_ip']]['requests'][1]['ut_google']=$now; 
		
		$serdata=serialize($ip_usage_data);
		file_put_contents($working_dir."/ipdata.obj",$serdata, LOCK_EX);
		
		return $ip_usage_data;
	}
}

// access google based on parameters and return raw html or "0" in case of an error
if (!function_exists('scrape_serp_google')) {
	function scrape_serp_google($search_string,$page,$local_data, $one_hundred_results, $ch, $filter)
	{
		$scrape_result="";
		
		$google_ip=$local_data['domain'];
		$hl=$local_data['lc'];
		
		if ($page == 0)
		{
			if ($one_hundred_results)
				$url="http://$google_ip/search?q=$search_string&hl=$hl&ie=utf-8&as_qdr=all&aq=t&rls=org:mozilla:us:official&client=firefox&num=100&filter=$filter";
			else
				$url="http://$google_ip/search?q=$search_string&hl=$hl&ie=utf-8&as_qdr=all&aq=t&rls=org:mozilla:us:official&client=firefox&num=10&filter=$filter";
		} 
		else
		{
			if ($one_hundred_results)
			{
				$num=$page*100;
				$url="http://$google_ip/search?q=$search_string&hl=$hl&ie=utf-8&as_qdr=all&aq=t&rls=org:mozilla:us:official&client=firefox&start=$num&num=100&filter=$filter";
			} else
			{
				$num=$page*10;
				$url="http://$google_ip/search?q=$search_string&hl=$hl&ie=utf-8&as_qdr=all&aq=t&rls=org:mozilla:us:official&client=firefox&start=$num&num=10&filter=$filter";
			}
		}
		//verbose("Debug, Search URL: $url$NL");
		
		curl_setopt ($ch, CURLOPT_URL, $url);
		$htmdata = curl_exec ($ch);
		if (!$htmdata)
		{
			$error = curl_error($ch);
			$info = curl_getinfo($ch);        
			verbose("Error scraping: $error");
			$scrape_result="SCRAPE_ERROR";
			sleep (3);
			return ['status'=>$scrape_result,'htmldata'=>NULL];
		} 
		elseif (strlen($htmdata) < 20)
		{
			$scrape_result="SCRAPE_EMPTY_SERP";
			sleep (3);
			return ['status'=>$scrape_result,'htmldata'=>NULL];		
		}
		
		
		if (strstr($htmdata,"computer virus or spyware application")) 
		{
			verbose("Google blocked us, we need more proxies ! Make sure you did not damage the IP management functions. ");
			$scrape_result="SCRAPE_DETECTED";
			return ['status'=>$scrape_result,'htmldata'=>NULL];	
		}
		if (strstr($htmdata,"entire network is affected")) 
		{
			verbose("Google blocked us, we need more proxies ! Make sure you did not damage the IP management functions. ");
			$scrape_result="SCRAPE_DETECTED";
			return ['status'=>$scrape_result,'htmldata'=>NULL];	
		}	
		if (strstr($htmdata,"http://www.download.com/Antivirus")) 
		{
			verbose("Google blocked us, we need more proxies ! Make sure you did not damage the IP management functions. ");
			$scrape_result="SCRAPE_DETECTED";
			return ['status'=>$scrape_result,'htmldata'=>NULL];	
		}	
	 	if (strstr($htmdata,"/images/yellow_warning.gif"))
		{
			verbose("Google blocked us, we need more proxies ! Make sure you did not damage the IP management functions. ");
			$scrape_result="SCRAPE_DETECTED";
			return ['status'=>$scrape_result,'htmldata'=>NULL];	
		}

		$scrape_result="SCRAPE_SUCCESS";
		return ['status'=>$scrape_result,'htmldata'=>$htmdata];
	}
}

/*
 * Parser
 * This function will parse the Google html code and create the data array with ranking information
 * The variable $process_result will contain general information or warnings/errors
 */
     //require_once "simple_html_dom.php";
if (!function_exists('process_raw_v2')) {
    function process_raw_v2($data, $page, $one_hundred_results, $working_dir)
    {
         $results=array();

         $html = new simple_html_dom();
         $html->load($data);
         $date = new DateTime();
         $html->save($working_dir.'/'.$date->getTimestamp().'.html');
         
         $interest = $html->find('div#ires div.g');
         verbose("found interesting elements: ".count($interest)."\n");
         $interest_num=0;
         foreach ($interest as $li)
         {
             $result = array('title'=>'undefined','host'=>'undefined','url'=>'undefined','desc'=>'undefined','type'=>'organic');
             $interest_num++;
             $h3 = $li->find('h3.r',0);
             if (!$h3)
             {
                 continue;
             }
             $a = $h3->find('a',0);
             if (!$a) continue;
             $result['title'] = html_entity_decode($a->plaintext);
             $lnk = urldecode($a->href);
             if ($lnk)
             {
                 if (preg_match('/.+(http[^&]*).+/', $lnk, $m))
                 {
                     
                     $result['url']=$m[1];
                     $tmp=parse_url($m[1]);
                     
                     if (isset($tmp['host']))
                         $result['host']=$tmp['host'];
                     else
                         verbose($result['url'].' has invalid URL');
                 } else
                 {
                     if (strstr($result['title'],'News')) $result['type']='news';
                 }
             }
             if ($result['type']=='organic')
             {
                 $sp = $li->find('span.st',0);
                 if ($sp)
                 {
                     $result['desc']=html_entity_decode($sp->plaintext);
                     $sp->clear();
                 }
             }
            $h3->clear();
            $a->clear();
            $li->clear();
             $results[]=$result;
         }
         $html->clear();

        // Analyze if more results are available (next page)
         $next = 0;
         if (strstr($data, "Next</a>"))
         {
             $next = 1;
         } else
         {
             if ($one_hundred_results)
             {
                 $needstart = ($page + 1) * 100;
             } else
             {
                 $needstart = ($page + 1) * 10;
             }
             $findstr = "start=$needstart";
             if (strstr($data, $findstr)) $next = 1;
         }
         $page++;
         if ($next)
         {
             $process_result = "PROCESS_SUCCESS_MORE"; // more data available
         } else
         {
             $process_result = "PROCESS_SUCCESS_LAST";
         } // last page reached

         return ['status'=>$process_result,'results'=>$results];
    }
}

if (!function_exists('rotate_proxy')) {
	function rotate_proxy($uid,$pwd, $portal)
	{
		$max_errors=3;
		$success=0;
		$status=-1;
		$res = array();
		$license=array();
		$proxy = array();
		$ch = null;

		while ($max_errors--)
		{
			$res=proxy_api("rotate", $uid,$pwd, $portal);  // will fill $PROXY

			$ip="";
			if ($res['status'] <= 0)
			{
				verbose("API error: Proxy API connection failed (Error $res). trying again soon..");
				sleep(21); // retry after a while
			} else
			{
				$proxy = $res['proxy'];
				verbose("API success: Received proxy IP $proxy[external_ip] on port $proxy[port].");
				$success=1;
				break;
			}
		}
		if ($success)
		{
			$ch=new_curl_session($proxy, $ch);
			$status= 1;
			$license=$res['license'];
			$proxy=$res['proxy'];
		} else
			$status =-1;

		return ['status'=>$status,'license'=>$license,'proxy'=>$proxy,'ch'=>$ch];
	}
}

/*
 * This is the API function for $portal.seo-proxies.com, currently supporting the "rotate" command
 * On success it will define the $PROXY variable, adding the elements ready,address,port,external_ip and return 1
 * On failure the return is <= 0 and the PROXY variable ready element is set to "0"
 */
if (!function_exists('extractBody')) {
	function extractBody($response_str)
	{
		$parts = preg_split('|(?:\r?\n){2}|m', $response_str, 2);
		if (isset($parts[1])) return $parts[1];
		return ' ';
	}
}

if (!function_exists('proxy_api')) {
	function proxy_api($cmd,$uid,$pwd,$portal="", $x="")
	{
		$status=1;
		$license = array();
		$proxy = array();

		$fp = fsockopen($portal, 80);
		if (!$fp) 
		{
			verbose("Unable to connect to proxy API");
			$status=-1;// connection not possible
		} else 
		{
			if ($cmd == "hello")
			{
				fwrite($fp, "GET /api.php?api=1&uid=$uid&pwd=$pwd&cmd=hello&extended=1 HTTP/1.0\r\nHost: $portal\r\nAccept: text/html, text/plain, text/*, */*;q=0.01\r\nAccept-Encoding: plain\r\nAccept-Language: en\r\n\r\n");
				
				stream_set_timeout($fp, 8);
				$res="";
				$n=0;
				while (!feof($fp)) 
				{
					if ($n++ > 4) break;
		  			$res .= fread($fp, 8192);
				}
			 	$info = stream_get_meta_data($fp);
			 	fclose($fp);
			
			 	if ($info['timed_out']) 
				{
					verbose('API: Connection timed out! ');
					//$LICENSE['active ']=0;
					//return -2; // api timeout
					$status = -2;
					$license['active']=0;
			  	} else 
				{
					if (strlen($res) > 1000) {
						$status = -3; // invalid api response (check the API website for possible problems)
					}

					$data=extractBody($res);
					$ar=explode(":",$data);
					if (count($ar) < 4) {
						$status = -100; // invalid api response
					}

					switch ($ar[0])
					{
						case "ERROR":
							verbose("API Error: $res");
							$license['active']=0;
							$status = 0; // Error received
						break;
						case "HELLO":
						  	$license['max_ips']=$ar[1]; 	// number of IPs licensed
							$license['total_ips']=$ar[2]; // number of IPs assigned
							$license['protocol']=$ar[3]; 	// current proxy protocol (http, socks, vpn)
							$license['processes']=$ar[4]; // number of proxy processes
							if ($license['total_ips'] > 0) 
								$license['active']=1; 
							else 
								$LICENSE['active']=0;
							$status=1;
						break;
						default:
							verbose("API Error: Received answer $ar[0], expected \"HELLO\"");
							$license['active']=0;
							$status = -101;// unknown API response
					}
				}
				
			} // cmd==hello
			
			
			
			if ($cmd == "rotate")
			{
				$proxy['ready']=0;
				fwrite($fp, "GET /api.php?api=1&uid=$uid&pwd=$pwd&cmd=rotate&randomness=0&offset=0 HTTP/1.0\r\nHost: $portal\r\nAccept: text/html, text/plain, text/*, */*;q=0.01\r\nAccept-Encoding: plain\r\nAccept-Language: en\r\n\r\n");
			 	stream_set_timeout($fp, 8);
				$res="";
				$n=0;
				while (!feof($fp)) 
				{
					if ($n++ > 4) break;
		  			$res .= fread($fp, 8192);
				}
			 	$info = stream_get_meta_data($fp);
			 	fclose($fp);
			
			 	if ($info['timed_out']) 
				{
					verbose('API: Connection timed out!');
					$status=-2; // api timeout
			  } else 
				{
					if (strlen($res) > 1000) 
						$status= -3; // invalid api response (check the API website for possible problems)
					
					$data=extractBody($res);
					$ar=explode(":",$data);
					if (count($ar) < 4) 
						$status= -100; // invalid api response

					switch ($ar[0])
					{
						case "ERROR":
							verbose("API Error: $res");
							$status= 0; // Error received
						break;
						case "ROTATE":
							$proxy['address']=$ar[1];
							$proxy['port']=$ar[2];
							$proxy['external_ip']=$ar[3];
							$proxy['ready']=1;
							usleep(250000); // additional time to avoid connecting during proxy bootup phase, to be 100% sure 1 second needs to be waited
							$status=1;
						break;
						default:
							verbose("API Error: Received answer $ar[0], expected \"ROTATE\"");
							$status= -101; // unknown API response
					}
		 		}
		 	} // cmd==rotate
		}

		return ['status'=>$status,'license'=>$license,'proxy'=>$proxy];
	}
}

if (!function_exists('dom2array')) {
	function dom2array($node) {
	    $res = array();
	    if ($node->nodeType == XML_TEXT_NODE) {
	        $res = $node->nodeValue;
	    } else {
	        if ($node->hasAttributes()) {
	            $attributes = $node->attributes;
	            if (!is_null($attributes)) {
	                $res['@attributes'] = array();
	                foreach($attributes as $index => $attr) {
	                    $res['@attributes'][$attr->name] = $attr->value;
	                }
	            }
	        }
	        if ($node->hasChildNodes()) {
	            $children = $node->childNodes;
	            for ($i = 0; $i < $children->length; $i++) {
	                $child = $children->item($i);
	                $res[$child->nodeName] = dom2array($child);
	            }
	            $res['textContent'] = $node->textContent;
	        }
	    }
	    return $res;
	}
}

if (!function_exists('store_cache')) {
	function store_cache( & $NodeContent = "", $nod) {
	    $NodList = $nod->childNodes;
	    for ($j = 0; $j < $NodList->length; $j++) {
	        $nod2 = $NodList->item($j);
	        $nodemane = $nod2->nodeName;
	        $nodevalue = $nod2->nodeValue;
	        if ($nod2->nodeType == XML_TEXT_NODE)
	            $NodeContent.= $nodevalue;
	        else {
	            $NodeContent.= "<$nodemane ";
	            $attAre = $nod2->attributes;
	            foreach($attAre as $value)
	            $NodeContent.= "{$value->nodeName}='{$value->nodeValue}'";
	            $NodeContent.= ">";
	            getContent($NodeContent, $nod2);
	            $NodeContent.= "</$nodemane>";
	        }
	    }
	}
}

if (!function_exists('dom2array_full')) {
	function dom2array_full($node) {
	    $result = array();
	    if ($node->nodeType == XML_TEXT_NODE) {
	        $result = $node->nodeValue;
	    } else {
	        if ($node->hasAttributes()) {
	            $attributes = $node->attributes;
	            if ((!is_null($attributes)) && (count($attributes)))
	                foreach($attributes as $index => $attr)
	            $result[$attr->name] = $attr->value;
	        }
	        if ($node->hasChildNodes()) {
	            $children = $node->childNodes;
	            for ($i = 0; $i < $children->length; $i++) {
	                $child = $children->item($i);
	                if ($child->nodeName != '#text')
	                    if (!isset($result[$child->nodeName]))
	                        $result[$child->nodeName] = dom2array($child);
	                    else {
	                        $aux = $result[$child->nodeName];
	                        $result[$child->nodeName] = array($aux);
	                        $result[$child->nodeName][] = dom2array($child);
	                    }
	            }
	        }
	    }
	    return $result;
	}
}

if (!function_exists('getip')) {
	function getip($PROXY) {
	    
	    if (!$PROXY['ready']) return -1; // proxy not ready

	    $curl_handle = curl_init();
	    curl_setopt($curl_handle, CURLOPT_URL, 'http://squabbel.com/ipxx.php'); // this site will return the plain IP address, great for testing if a proxy is ready
	    curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 10);
	    curl_setopt($curl_handle, CURLOPT_TIMEOUT, 10);
	    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
	    $curl_proxy = "$PROXY[address]:$PROXY[port]";
	    curl_setopt($curl_handle, CURLOPT_PROXY, $curl_proxy);
	    $tested_ip = curl_exec($curl_handle);

	    if (preg_match("^([1-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])(\.([0-9]|[1-9][0-9]|1[0-9][0-9]|2[0-4][0-9]|25[0-5])){3}^", $tested_ip)) {
	        curl_close($curl_handle);
	        return $tested_ip;
	    } else {
	        $info = curl_getinfo($curl_handle);
	        curl_close($curl_handle);
	        return 0; // possible error would be a wrong authentication IP or a firewall
	    }
	}
}

if (!function_exists('new_curl_session')) {
	function new_curl_session($proxy, $ch = NULL) {
	    //global $PROXY;
	    if ((!isset($proxy['ready'])) || (!$proxy['ready'])) return $ch; // proxy not ready

	    if (isset($ch) && ($ch != NULL))
	        curl_close($ch);
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_HEADER, 0);
	    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	    $curl_proxy = "$proxy[address]:$proxy[port]";
	    curl_setopt($ch, CURLOPT_PROXY, $curl_proxy);
	    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
	    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
	    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows; U; Windows NT 5.0; en; rv:1.9.0.4) Gecko/2009011913 Firefox/3.0.6");
	    return $ch;
	}
}

if (!function_exists('rmkdir')) {
	function rmkdir($path, $mode = 0755) {
	    if (file_exists($path)) return 1;
	    return@ mkdir($path, $mode);
	}
}

/*
 * For country&language specific searches
 */
if (!function_exists('get_google_cc')) {
	function get_google_cc($cc, $lc, $uid, $pwd, $portal) {
	    $fp = fsockopen($portal, 80);
	    if (!$fp) {
	        verbose("Unable to connect to google_cc API");
	        return NULL; // connection not possible
	    } else {
	        fwrite($fp, "GET /g_api.php?api=1&uid=$uid&pwd=$pwd&cmd=google_cc&cc=$cc&lc=$lc HTTP/1.0\r\nHost: $portal\r\nAccept: text/html, text/plain, text/*, */*;q=0.01\r\nAccept-Encoding: plain\r\nAccept-Language: en\r\n\r\n");
	        stream_set_timeout($fp, 8);
	        $res = "";
	        $n = 0;
	        while (!feof($fp)) {
	            if ($n++ > 4) break;
	            $res.= fread($fp, 8192);
	        }
	        $info = stream_get_meta_data($fp);
	        fclose($fp);

	        if ($info['timed_out']) {
	            verbose('API: Connection timed out! ');
	            return NULL; // api timeout
	        } else {
	            $data = extractBody($res);
	            // print_r($data);
	            // die;
	            $obj = unserialize($data);
	            if (isset($obj['error'])) 
	            	verbose($obj['error']);

	            if (isset($obj['info'])) 
	            	verbose($obj['info']);
	            return $obj['data'];

	            if (strlen($data) < 4) 
	            	return NULL; // invalid api response
	        }
	    }
	}
}

?>