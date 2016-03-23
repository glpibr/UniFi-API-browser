<?php
/*
Unifi PHP API

- this Unifi API client comes bundled with the Unifi API Browser tool and is based on the work done by
  the following developers:
    domwo: http://community.ubnt.com/t5/UniFi-Wireless/little-php-class-for-unifi-api/m-p/603051
    fbagnol: https://github.com/fbagnol/class.unifi.php
  and the API as published by Ubiquiti:
    https://dl.ubnt.com/unifi/4.7.6/unifi_sh_api

VERSION: 1.0.1

NOTE:
this Class will only work with Unifi Controller versions 4.x. There are no checks to prevent you from
trying to use it with a pre-4.x version controller.

IMPORTANT CHANGES:
- function "get_vouchers" has been removed and has been replaced by "stat_voucher"

------------------------------------------------------------------------------------

The MIT License (MIT)

Copyright (c) 2015, Slooffmaster

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

*/
class unifiapi {
   public $user         = '';
   public $password     = '';
   public $site         = 'default';
   public $baseurl      = 'https://127.0.0.1:8443';
   public $version      = '4.8.6';
   public $is_loggedin  = false;
   private $cookies     = '/tmp/unify_browser';
   public $debug        = false;

   function __construct($user = '', $password = '', $baseurl = '', $site = '', $version = '') {
      if (!empty($user)) $this->user                = $user;
      if (!empty($password)) $this->password        = $password;
      if (!empty($baseurl)) $this->baseurl          = $baseurl;
      if (!empty($site)) $this->site                = $site;
      if (!empty($version)) $this->version          = $version;
   }

   function __destruct() {
      if ($this->is_loggedin) {
         $this->logout();
      }
   }

   /*
   Login to Unifi Controller
   */
   public function login() {
      $this->cookies  = '';
      $ch             = $this->get_curl_obj();

      curl_setopt($ch, CURLOPT_HEADER, 1);
      curl_setopt($ch, CURLOPT_REFERER, $this->baseurl.'/login');
      curl_setopt($ch, CURLOPT_URL, $this->baseurl.'/api/login');
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('username' => $this->user, 'password' => $this->password)));

      if ($this->debug === true) {
         curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
      }

      if(($content = curl_exec($ch)) === false) {
         error_log('curl error: ' . curl_error($ch));
      }

      if ($this->debug === true) {
         print '<pre>';
         print PHP_EOL.'-----LOGIN-------------------'.PHP_EOL;
         print_r (curl_getinfo($ch));
         print PHP_EOL.'-----RESPONSE----------------'.PHP_EOL;
         print $content;
         print PHP_EOL.'-----------------------------'.PHP_EOL;
         print '</pre>';
      }

      $header_size  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
      $body         = trim(substr($content, $header_size));
      $code         = curl_getinfo($ch, CURLINFO_HTTP_CODE);

      curl_close ($ch);
      preg_match_all('|Set-Cookie: (.*);|U', substr($content, 0, $header_size), $results);
      if (isset($results[1])) {
         $this->cookies = implode(';', $results[1]);
         if (!empty($body)) {
            if (($code >= 200) && ($code < 400)) {
               if (strpos($this->cookies,'unifises') !== FALSE) {
                  $this->is_loggedin = true;
               }
            }
            if ($code === 400) {
                error_log('we have received an HTTP response status: 400. Probably a controller login failure');
                return $code;
            }
         }
      }
      return $this->is_loggedin;
   }

   /*
   Logout from Unifi Controller
   */
   public function logout() {
      if (!$this->is_loggedin) return false;
      $content            = $this->exec_curl($this->baseurl.'/logout');
      $this->is_loggedin  = false;
      $this->cookies      = '';
      return true;
   }

   /*
   Authorize a MAC address
   parameter <MAC address>
   parameter <minutes> = minutes (from now) until authorization expires
   optional parameter <up> = upload speed limit in kbps
   optional parameter <down> = download speed limit in kbps
   optional parameter <MBytes> = data transfer limit in MB
   optional parameter <ap_mac> = AP MAC address to which client is connected, should result in faster authorization
   return true on success
   */
   public function authorize_guest($mac, $minutes, $up = NULL, $down = NULL, $MBytes = NULL, $ap_mac = NULL) {
      if (!$this->is_loggedin) return false;
      $mac              = strtolower($mac);
      $return           = false;
      $authorize_array  = array('cmd' => 'authorize-guest', 'mac' => $mac, 'minutes' => $minutes);

      /*
      if we have received values for up/down/MBytes we append them to the payload array to be submitted
      */
      if (isset($up)) $authorize_array['up'] = $up;
      if (isset($down)) $authorize_array['down'] = $down;
      if (isset($MBytes)) $authorize_array['bytes'] = $MBytes;
      if (isset($ap_mac)) $authorize_array['ap_mac'] = $ap_mac;
      $json             = json_encode($authorize_array);
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/stamgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   unauthorize a MAC address
   parameter <MAC address>
   return true on success
   */
   public function unauthorize_guest($mac) {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $mac              = strtolower($mac);
      $json             = json_encode(array('cmd' => 'unauthorize-guest', 'mac' => $mac));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/stamgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   reconnect a client
   parameter <MAC address>
   return true on success
   */
   public function reconnect_sta($mac) {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $mac              = strtolower($mac);
      $json             = json_encode(array('cmd' => 'kick-sta', 'mac' => $mac));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/stamgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   block a client
   parameter <MAC address>
   return true on success
   */
   public function block_sta($mac) {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $mac              = strtolower($mac);
      $json             = json_encode(array('cmd' => 'block-sta', 'mac' => $mac));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/stamgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   unblock a client
   parameter <MAC address>
   return true on success
   */
   public function unblock_sta($mac) {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $mac              = strtolower($mac);
      $json             = json_encode(array('cmd' => 'unblock-sta', 'mac' => $mac));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/stamgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   daily stats method
   optional parameter <start>
   optional parameter <end>
   NOTE: defaults to the past 30*7*24 hours
         "bytes" are no longer returned with controller version 4.9.1 and later
   */
   public function stat_daily_site($start = NULL, $end = NULL) {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $end              = is_null($end) ? ((time()-(time() % 3600))*1000) : $end;
      $start            = is_null($start) ? $end-18144000000 : $start;
      $json             = json_encode(array('attrs' => array('bytes', 'wan-tx_bytes', 'wan-rx_bytes', 'wlan_bytes', 'num_sta', 'lan-num_sta', 'wlan-num_sta', 'time'), 'start' => $start, 'end' => $end));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/report/daily.site','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $test) {
                  $return[]= $test;
               }
            }
         }
      }
      return $return;
   }

   /*
   hourly stats method for a site
   optional parameter <start>
   optional parameter <end>
   NOTE: defaults to the past 7*24 hours
         "bytes" are no longer returned with controller version 4.9.1 and later
   */
   public function stat_hourly_site($start = NULL, $end = NULL) {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $end              = is_null($end) ? ((time())*1000) : $end;
      $start            = is_null($start) ? $end-604800000 : $start;
      $json             = json_encode(array('attrs' => array('bytes', 'wan-tx_bytes', 'wan-rx_bytes', 'wlan_bytes', 'num_sta', 'lan-num_sta', 'wlan-num_sta', 'time'), 'start' => $start, 'end' => $end));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/report/hourly.site','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $test) {
                  $return[]= $test;
               }
            }
         }
      }
      return $return;
   }

   /*
   hourly stats method for all access points
   optional parameter <start>
   optional parameter <end>
   NOTE: defaults to the past 7*24 hours, but unifi controller does not
   keep these stats longer than 5 hours (controller v 4.6.6)
   */
   public function stat_hourly_aps($start = NULL, $end = NULL) {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $end              = is_null($end) ? ((time())*1000) : $end;
      $start            = is_null($start) ? $end-604800000 : $start;
      $json             = json_encode(array('attrs' => array('bytes', 'num_sta', 'time'), 'start' => $start, 'end' => $end));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/report/hourly.ap','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $test) {
                  $return[]= $test;
               }
            }
         }
      }
      return $return;
   }

   /*
   show all login sessions
   optional parameter <start>
   optional parameter <end>
   NOTE: default start value is 7 days ago
   */
   public function stat_sessions($start = NULL, $end = NULL) {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $end              = is_null($end) ? time() : $end;
      $start            = is_null($start) ? $end-604800 : $start;
      $json             = json_encode(array('type'=> 'all', 'start' => $start, 'end' => $end));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/session','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $session) {
                  $return[]= $session;
               }
            }
         }
      }
      return $return;
   }

   /*
   show all authorizations
   optional parameter <start>
   optional parameter <end>
   NOTE: defaults to the past 7*24 hours
   */
   public function stat_auths($start = NULL, $end = NULL) {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $end              = is_null($end) ? time() : $end;
      $start            = is_null($start) ? $end-604800 : $start;
      $json             = json_encode(array('start' => $start, 'end' => $end));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/authorization','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $auth) {
                  $return[]= $auth;
               }
            }
         }
      }
      return $return;
   }

   /*
   get details of all clients ever connected to the site
   optional parameter <historyhours>
   NOTE: "within" only allows to select clients that were online in that period.
   Stats per client are totals, irrespective of "within" value
   defaults to 1 year of history
   */
   public function stat_allusers($historyhours = NULL) {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $historyhours     = is_null($historyhours) ? 8760 : $historyhours;
      $json             = json_encode(array('type' => 'all', 'conn' => 'all', 'within' => $historyhours));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/alluser','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $stats) {
                  $return[]= $stats;
               }
            }
         }
      }
      return $return;
   }

   /*
   list guests
   returns an array of guest objects
   */
   public function list_guests() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/guest','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $guest) {
                  $return[]= $guest;
               }
            }
         }
      }
      return $return;
   }

   /*
   list clients
   returns an array of client objects
   */
   public function list_clients() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/sta','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $client) {
                  $return[]= $client;
               }
            }
         }
      }
      return $return;
   }

   /*
   list user groups
   returns an array of user group objects
   */
   public function list_usergroups() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/usergroup','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $usergroup) {
                  $return[]= $usergroup;
               }
            }
         }
      }
      return $return;
   }

   /*
   list health metrics
   returns an array of health metric objects
   */
   public function list_health() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/health','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $health) {
                  $return[]= $health;
               }
            }
         }
      }
      return $return;
   }

   /*
   list dashboard metrics
   returns an array of dashboard metric objects (available since controller version 4.9.1.alpha)
   */
   public function list_dashboard() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/dashboard','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $dashboard) {
                  $return[]= $dashboard;
               }
            }
         }
      }
      return $return;
   }

   /*
   list users
   returns an array of known user objects
   */
   public function list_users() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/user','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $user) {
                  $return[]= $user;
               }
            }
         }
      }
      return $return;
   }

   /*
   list access points
   returns an array of known access point objects
   */
   public function list_aps() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/device','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $ap) {
                  $return[]= $ap;
               }
            }
         }
      }
      return $return;
   }

   /*
   list rogue access points
   returns an array of known roque access point objects
   json parameter <within> {within: "<hoursback eg 24>"} is optional
   */
   public function list_rogueaps() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array('within' => '24'));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/rogueap','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $rogue) {
                  $return[]= $rogue;
               }
            }
         }
      }
      return $return;
   }

   /*
   list sites
   returns a list sites hosted on this controller with some details
   */
   public function list_sites() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/self/sites'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $site) {
                  $return[]= $site;
               }
            }
         }
      }
      return $return;
   }

   /*
   list wlan_groups
   returns an array of known wlan_groups
   */
   public function list_wlan_groups() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/wlangroup'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $wlan_group) {
                  $return[]= $wlan_group;
               }
            }
         }
      }
      return $return;
   }

   /*
   stat sysinfo
   returns an array of known sysinfo data
   */
   public function stat_sysinfo() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/sysinfo'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $sysinfo) {
                  $return[]= $sysinfo;
               }
            }
         }
      }
      return $return;
   }

   /*
   list self
   returns an array of information about the logged in user
   */
   public function list_self() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/self'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $selfinfo) {
                  $return[]= $selfinfo;
               }
            }
         }
      }
      return $return;
   }

   /*
   list networkconf
   returns an array of network configuration data
   */
   public function list_networkconf() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/networkconf'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $networkconf) {
                  $return[]= $networkconf;
               }
            }
         }
      }
      return $return;
   }

   /*
   stat vouchers
   optional parameter <create_time>
   returns an array of hotspot voucher objects
   */
   public function stat_voucher($create_time = NULL) {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      if (trim($create_time) != NULL) {
        $json=json_encode(array('create_time' => $create_time));
      }
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/voucher','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $voucher) {
                  $return[]= $voucher;
               }
            }
         }
      }
      return $return;
   }

   /*
   stat payment
   returns an array of hotspot payments
   */
   public function stat_payment() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/payment'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $payment) {
                  $return[]= $payment;
               }
            }
         }
      }
      return $return;
   }

   /*
   list hotspotop
   returns an array of hotspot operators
   */
   public function list_hotspotop() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/hotspotop'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $hotspotop) {
                  $return[]= $hotspotop;
               }
            }
         }
      }
      return $return;
   }

   /*
   create voucher(s)
   parameter <minutes> = minutes the voucher is valid after activation
   parameter <number_of_vouchers_to_create>
   optional parameter <note> = note text to add to voucher when printing
   optional parameter <up> = upload speed limit in kbps
   optional parameter <down> = download speed limit in kbps
   optional parameter <MBytes> = data transfer limit in MB
   returns an array of vouchers codes (NOTE: without the "-" in the middle)
   */
   public function create_voucher($minutes, $number_of_vouchers_to_create = 1, $note = NULL, $up = NULL, $down = NULL, $MBytes = NULL) {
      if (!$this->is_loggedin) return false;
      $return   = array();
      $json     = array('cmd' => 'create-voucher', 'expire' => $minutes, 'n' => $number_of_vouchers_to_create);

      /*
      if we have received values for note/up/down/MBytes we append them to the payload array to be submitted
      */
      if (isset($note))   $json += array('note' => trim($note));
      if (isset($up))     $json += array('up' => $up);
      if (isset($down))   $json += array('down' => $down);
      if (isset($MBytes)) $json += array('bytes' => $MBytes);

      $json             = json_encode($json);
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/hotspot','json='.$json));
      if ($content_decoded->meta->rc == 'ok') {
         if (is_array($content_decoded->data)) {
            $obj = $content_decoded->data[0];
            foreach ($this->get_vouchers($obj->create_time) as $voucher)  {
               $return[]= $voucher->code;
            }
         }
      }
      return $return;
   }

   /*
   list port forwarding stats
   returns an array of port forwarding stats
   */
   public function list_portforward_stats() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/portforward','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $portforward) {
                  $return[]= $portforward;
               }
            }
         }
      }
      return $return;
   }

   /*
   list port forwarding settings
   returns an array of port forwarding settings
   */
   public function list_portforwarding() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/portforward'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $portforward) {
                  $return[]= $portforward;
               }
            }
         }
      }
      return $return;
   }

   /*
   list dynamic_dns settings
   returns an array of dynamic DNS settings
   */
   public function list_dynamicdns() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/dynamicdns'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $dynamicdns) {
                  $return[]= $dynamicdns;
               }
            }
         }
      }
      return $return;
   }

   /*
   list port configuration
   returns an array of port configurations
   */
   public function list_portconf() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/portconf'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $portconf) {
                  $return[]= $portconf;
               }
            }
         }
      }
      return $return;
   }

   /*
   list VoIP extensions
   returns an array of VoIP extensions
   */
   public function list_extension() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/extension'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $extension) {
                  $return[]= $extension;
               }
            }
         }
      }
      return $return;
   }

   /*
   list site settings
   returns an array of site configuration settings
   */
   public function list_settings() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/get/setting'));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $setting) {
                  $return[]= $setting;
               }
            }
         }
      }
      return $return;
   }

   /*
   reboot an access point
   parameter <MAC address>
   return true on success
   */
   public function restart_ap($mac) {
      if (!$this->is_loggedin) return false;
      $mac              = strtolower($mac);
      $return           = false;
      $json             = json_encode(array('cmd' => 'restart', 'mac' => $mac));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/devmgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   start flashing LED of an access point for locating purposes
   parameter <MAC address>
   return true on success
   */
   public function set_locate_ap($mac) {
      if (!$this->is_loggedin) return false;
      $mac              = strtolower($mac);
      $return           = false;
      $json             = json_encode(array('cmd' => 'set-locate', 'mac' => $mac));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/devmgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   stop flashing LED of an access point for locating purposes
   parameter <MAC address>
   return true on success
   */
   public function unset_locate_ap($mac) {
      if (!$this->is_loggedin) return false;
      $mac              = strtolower($mac);
      $return           = false;
      $json             = json_encode(array('cmd' => 'unset-locate', 'mac' => $mac));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/cmd/devmgr','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   switch LEDs of all the access points ON
   return true on success
   */
   public function site_ledson() {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $json             = json_encode(array('led_enabled' => true));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/set/setting/mgmt','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   switch LEDs of all the access points OFF
   return true on success
   */
   public function site_ledsoff() {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $json             = json_encode(array('led_enabled' => false));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/set/setting/mgmt','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   set access point radio settings
   parameter <ap_id>
   parameter <radio>(default=ng)
   parameter <channel>
   parameter <ht>(default=20)
   parameter <tx_power_mode>
   parameter <tx_power>(default=0)
   return true on success
   */
   public function set_ap_radiosettings($ap_id, $radio, $channel, $ht, $tx_power_mode, $tx_power) {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $jsonsettings     = json_encode(array('radio' => $radio, 'channel' => $channel, 'ht' => $ht, 'tx_power_mode' => $tx_power_mode, 'tx_power' =>$tx_power));
      $json             = '{"radio_table": ['.$jsonsettings.']}';
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/upd/device/'.$ap_id,'json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   set guest login settings
   parameter <portal_enabled>
   parameter <portal_customized>
   parameter <redirect_enabled>
   parameter <redirect_url>
   parameter <x_password>
   parameter <expire_number>
   parameter <expire_unit>
   parameter <site_id>
   NOTE: both portal parameters are set to the same value!
   return true on success
   */
   public function set_guestlogin_settings($portal_enabled, $portal_customized, $redirect_enabled, $redirect_url, $x_password, $expire_number, $expire_unit, $site_id) {
      if (!$this->is_loggedin) return false;
      $return = false;
      $json = json_encode(array('portal_enabled' => $portal_enabled, 'portal_customized' => $portal_customized,
                                'redirect_enabled' => $redirect_enabled, 'redirect_url' => $redirect_url,
                                'x_password' => $x_password, 'expire_number' => $expire_number,
                                'expire_unit' => $expire_unit, 'site_id' => $site_id), JSON_UNESCAPED_SLASHES);
      $content_decoded = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/set/setting/guest_access','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   rename access point
   parameter <ap_id>
   parameter <apname>
   return true on success
   */
   public function rename_ap($ap_id, $apname) {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $json             = json_encode(array('name' => $apname));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/upd/device/'.$ap_id,'json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   set wlan settings
   parameter <wlan_id>
   parameter <name>
   parameter <x_passphrase>
   return true on success
   */
   public function set_wlansettings($wlan_id, $name, $x_passphrase) {
      if (!$this->is_loggedin) return false;
      $return           = false;
      $json             = json_encode(array('name' => $name, 'x_passphrase' => $x_passphrase));
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/upd/wlanconf/'.$wlan_id,'json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            $return = true;
         }
      }
      return $return;
   }

   /*
   list events
   returns an array of known events
   */
   public function list_events() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/stat/event','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $event) {
                  $return[]= $event;
               }
            }
         }
      }
      return $return;
   }

   /*
   list wireless settings
   returns an array of wireless networks and settings
   */
   public function list_wlanconf() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/wlanconf','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $wlan) {
                  $return[]= $wlan;
               }
            }
         }
      }
      return $return;
   }

   /*
   list alarms
   returns an array of known alarms
   */
   public function list_alarms() {
      if (!$this->is_loggedin) return false;
      $return           = array();
      $json             = json_encode(array());
      $content_decoded  = json_decode($this->exec_curl($this->baseurl.'/api/s/'.$this->site.'/list/alarm','json='.$json));
      if (isset($content_decoded->meta->rc)) {
         if ($content_decoded->meta->rc == 'ok') {
            if (is_array($content_decoded->data)) {
               foreach ($content_decoded->data as $alarm) {
                  $return[]= $alarm;
               }
            }
         }
      }
      return $return;
   }

   private function exec_curl($url, $data = '') {
      $ch = $this->get_curl_obj();
      curl_setopt($ch, CURLOPT_URL, $url);

      if (trim($data) != '') {
         curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
      } else {
         curl_setopt($ch, CURLOPT_POST, FALSE);
      }

      if(($content = curl_exec($ch)) === false) {
         error_log('curl error: ' . curl_error($ch));
      }

      if ($this->debug === true) {
         print '<pre>';
         print PHP_EOL.'-----cURL INFO---------------'.PHP_EOL;
         print_r (curl_getinfo($ch));
         print PHP_EOL.'-----URL & PAYLOAD-----------'.PHP_EOL;
         print $url.PHP_EOL;
         print $data;
         print PHP_EOL.'-----RESPONSE----------------'.PHP_EOL;
         print $content;
         print PHP_EOL.'-----------------------------'.PHP_EOL;
         print '</pre>';
      }
      curl_close ($ch);
      return $content;
   }

   private function get_curl_obj() {
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_POST, TRUE);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);

      if ($this->debug === true) {
         curl_setopt($ch, CURLOPT_VERBOSE, TRUE);
      }

      if ($this->cookies != '') {
         curl_setopt($ch, CURLOPT_COOKIESESSION, TRUE);
         curl_setopt($ch, CURLOPT_COOKIE, $this->cookies);
      }
      return $ch;
   }
}
?>