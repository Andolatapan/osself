<?php
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', 0);
App::uses('AppController', 'Controller');
App::uses('Common', 'Utility');

class UsersController extends AppController {

    public $uses = array('User', 'Lead', 'Leadinfo');
    public $components = array('Paginator', 'Auth', 'Session', 'Cookie', 'Email', 'Sendgrid', 'Checkip', 'Format', 'Sendemail', 'Query');

    // public $helpers=array('Datetime');
    public function beforeFilter() {

        $this->disableCache();
        $this->Auth->fields = array('username' => 'username', 'password' => 'password');
        $this->Auth->loginRedirect = '/users/index';
        $this->Auth->autoRedirect = false;
        $this->Auth->authenticate = array(
            'Form' => array(
                'scope' => array('User.is_active' => '1')
            )
        );
        Security::setHash('md5');
        $this->Auth->allow('login', 'confirm', 'forgotPassword', 'changePassword', 'saveSubscriptionDetails', 'get_lead_reports_os_community');
        parent::beforeFilter();
    }

    public function dashboard() {
	}

    public function login() {
        //echo phpinfo();exit; //PHP Version 5.4.27
        if ($this->request->is('post') || $this->Session->read('User.id')) {

            if ($this->Auth->login()) {
           
                
				$this->User->keepPassChk($this->Auth->user('id'));
				
                $this->Session->write('User', $this->Auth->user());
                $date = date("Y-m-d H:i:s");
                $this->Session->write('last_login', $date);
                $this->redirect(
                        array('controller' => 'leads', 'action' => 'leads_ajax')
                );
            }
            $this->Session->setFlash(
                    'Invalid Email or Password, try again', 'flash_message_error', array()
            );
        }
    }
	
	public function change_password() {
        $id = $this->Auth->user('id');
        $this->set('uid', $id);
        if ($this->request->is('ajax')) {
            $user_id = $this->request->data['user_id'];
            $old_password = $this->request->data['old_password'];
            $password = $this->request->data['password'];
            $new_password = md5($password);
            $userData = $this->User->find('first', array('conditions' => array('id' => $user_id)));
			
            if (md5($old_password) != $userData['User']['password']) {
                echo "error";
                exit;
            } else {
                $this->User->query("UPDATE `users` SET `password`='" . $new_password . "' WHERE `id`=" . $user_id);
				
                $this->User->keepPassChk($this->Auth->user('id'));
				
                echo "success";
                exit;
            }
        }
    }
	
    public function logout() {
        $id = $this->Session->read('User.id');
        $date = $this->Session->read('last_login');
        $this->User->id = $id;
        $this->User->saveField('last_login', $date);
        $this->Session->destroy();
        $this->Auth->logout();
        $this->redirect('/login');
    }

    public function saveprospects() {
        $is_private = $this->Format->checkprivateip();
        if(!$is_private){
            $this->layout = "ajax";
            $this->loadModel('Prospect');
            $this->loadModel('Prospectvisit');
            $this->loadModel('Bot');

            $clientIP = $this->request->data['clientip'];
            $Domain = $this->request->data['domain'];
            $urlVisit = $this->request->data['urlVisit'];
            $VisitDate = $this->request->data['lastmodified'];
            $referURL = $this->request->data['referURL'];

            if (preg_match("/http:\/\/t.co/", $referURL) || preg_match("/https:\/\/t.co/", $referURL)) {
                $originalRefer = "https://www.twitter.com/";
            } else if (preg_match("/facebook.com/", $referURL)) {
                $originalRefer = "https://www.facebook.com";
            } else {
                $originalRefer = $referURL;
            }
            $selectLeadFirst = $this->Lead->find('count', array('conditions' => array('IP_ADDRESS' => $clientIP, 'SOURCE_NAME' => $Domain)));
            if ($selectLeadFirst == 0) { //If the prospect is not present in Lead table then allow him to insert into prospects table
                $isExistIP = $this->Bot->find('count', array('conditions' => array('IP' => $clientIP)));
                if (isset($isExistIP) && $isExistIP == 0) {
                    $findIP = $this->Prospect->find('first', array('conditions' => array('IP' => $clientIP, 'SOURCE_NAME' => $Domain)));
                    if (count($findIP) == 0) {
                        $data = file_get_contents('http://api.ipinfodb.com/v3/ip-city/?key=' . IP2LOC_API_KEY_TRACK . '&ip=' . $clientIP . '&format=json');
                        $data = json_decode($data, true);

                        $hostname = file_get_contents("http://ipinfo.io/" . $clientIP . "/json");
                        $hostname = json_decode($hostname, true);

                        $dataProspect['IP'] = $data['ipAddress'];
                        $dataProspect['SOURCE_NAME'] = $Domain;
                        $dataProspect['REFER'] = $originalRefer;
                        $dataProspect['COUNTRY_CODE'] = $data['countryCode'];
                        $dataProspect['COUNTRY'] = $data['countryName'];
                        $dataProspect['STATE'] = $data['regionName'];
                        $dataProspect['CITY'] = $data['cityName'];
                        $dataProspect['ZIPCODE'] = $data['zipCode'];
                        $dataProspect['LATITUDE'] = $data['latitude'];
                        $dataProspect['LONGITUDE'] = $data['longitude'];
                        $dataProspect['TIMEZONE'] = $data['timeZone'];
                        $dataProspect['CREATED'] = date('Y-m-d H:i:s');

                        $dataProspect['HOSTNAME'] = $hostname['hostname'];
                        $dataProspect['ORG'] = $hostname['org'];

                        $this->Prospect->save($dataProspect);

                        $lastInsertID = $this->Prospect->getLastInsertId();
                    } else {
                        $lastInsertID = $findIP['Prospect']['ID'];
                    }
                    $findProsID = $this->Prospectvisit->find('first', array('conditions' => array('PROSPECTID' => $lastInsertID)));
                    if (count($findProsID) == 0) {
                        $UrlVisitArr['urlname'] = $urlVisit;
                        $UrlVisitArr['visitdate'] = $VisitDate;
                        $JsonURL = json_encode($UrlVisitArr);

                        $dataProspectUrls['PROSPECTID'] = $lastInsertID;
                        $dataProspectUrls['URLVISIT'] = $JsonURL;
                        $dataProspectUrls['CREATED'] = date('Y-m-d H:i:s');
                        $dataProspectUrls['UPDATED'] = date('Y-m-d H:i:s');
                        $this->Prospectvisit->save($dataProspectUrls);
                    } else {
                        $this->Prospectvisit->id = $findProsID['Prospectvisit']['ID'];
                        $dataProspectUrls['UPDATED'] = date('Y-m-d H:i:s');
                        $UrlVisitArr['urlname'] = $urlVisit;
                        $UrlVisitArr['visitdate'] = $VisitDate;
                        $JsonURL = json_encode($UrlVisitArr);

                        $totalUrlVisit = $findProsID['Prospectvisit']['URLVISIT'] . "@#$@#$" . $JsonURL;

                        $dataProspectUrls['URLVISIT'] = $totalUrlVisit;
                        $this->Prospectvisit->query("UPDATE `prospectvisits` SET `UPDATED`='" . $dataProspectUrls['UPDATED'] . "', `URLVISIT`='" . $dataProspectUrls['URLVISIT'] . "' WHERE `ID`='" . $findProsID['Prospectvisit']['ID'] . "'");
                    }
                }
            }
        }
        exit;
    }

    public function saveleads() {
        $is_private = $this->Format->checkprivateip();
        if(!$is_private){
            $this->layout = "ajax";
            $this->loadModel('Lead');
            $this->loadModel('Prospect');
            $this->loadModel('Prospectlead');
            $this->loadModel('Prospectvisit');
            $this->loadModel('Prospectvisitlead');

            $dataSave['USER_CODE'] = $this->request->data['usr_code'];
            $dataSave['SOURCE_NAME'] = $this->request->data['domain'];
            $dataSave['REFER'] = $this->request->data['refer'];
            $dataSave['NAME'] = $this->request->data['name'];
            $dataSave['EMAIL'] = $this->request->data['email'];
            $dataSave['PHONE'] = $this->request->data['phone'];
            $dataSave['WANT_TO_START'] = addslashes($this->request->data['start']);
            $dataSave['TYPE_OF_APP'] = $this->request->data['typeapps'];
            $dataSave['DESCRIPTION'] = addslashes($this->request->data['about']);
            $dataSave['IP_ADDRESS'] = $this->request->data['loc'];
            $dataSave['CREATED'] = date('Y-m-d H:i:s');
            $dataSave['PAGE_NAME'] = $this->request->data['page_name'] ? $this->request->data['page_name'] : '';

            if (preg_match("/http:\/\/t.co/", $dataSave['REFER']) || preg_match("/https:\/\/t.co/", $dataSave['REFER'])) {
                $originalRefer = "https://www.twitter.com/";
            } else if (preg_match("/facebook.com/", $dataSave['REFER'])) {
                $originalRefer = "https://www.facebook.com";
            } else {
                $originalRefer = $dataSave['REFER'];
            }
            $data = file_get_contents('http://api.ipinfodb.com/v3/ip-city/?key=' . IP2LOC_API_KEY_TRACK . '&ip=' . $dataSave['IP_ADDRESS'] . '&format=json');
            $data = json_decode($data, true);
    		
    		$getEmailId = 1;
    		
    		if(isset($this->request->data['ostype']) && strtolower($this->request->data['ostype']) == 'os'){
    			$IfEmailIdExistinLeadtrack = $this->Lead->query("SELECT * FROM `leads` WHERE `EMAIL`='".$dataSave['EMAIL']."' AND `SOURCE_NAME` LIKE '%orangescrum.com%'");
    			if(count($IfEmailIdExistinLeadtrack) > 0){
    				$getEmailId = 0;
    			}
    		}

    		if($getEmailId){
    	        $this->Lead->query("INSERT INTO leads SET `USER_CODE`='" . $dataSave['USER_CODE'] . "', `REFER`='" . $originalRefer . "', `SOURCE_NAME`='" . $dataSave['SOURCE_NAME'] . "', `NAME`='" . $dataSave['NAME'] . "', `EMAIL`='" . $dataSave['EMAIL'] . "', `PHONE`='" . $dataSave['PHONE'] . "', `WANT_TO_START`='" . $dataSave['WANT_TO_START'] . "', `TYPE_OF_APP`='" . $dataSave['TYPE_OF_APP'] . "', `DESCRIPTION`='" . $dataSave['DESCRIPTION'] . "', `IP_ADDRESS`='" . $dataSave['IP_ADDRESS'] . "', `COUNTRY_CODE`='" . $data['countryCode'] . "', `COUNTRY`='" . $data['countryName'] . "', `STATE`='" . $data['regionName'] . "', `CITY`='" . $data['cityName'] . "', `ZIPCODE`='" . $data['zipCode'] . "', `LATITUDE`='" . $data['latitude'] . "', `LONGITUDE`='" . $data['longitude'] . "', `TIMEZONE`='" . $data['timeZone'] . "', `CREATED`='" . $dataSave['CREATED'] . "', `PAGE_NAME`='" . $dataSave['PAGE_NAME'] . "'");
    			
    			if(isset($this->request->data['loginuser']) && $this->request->data['loginuser'] != ''){
    				$user_pages_static = '{"urls":[{"url":"http://www.orangescrum.com/login","lastmodified":"'.gmdate('Y-m-d H:i:s').'"}]}';
    				$this->Leadinfo->query("REPLACE INTO leadinfos (USER_CODE,USER_URLS,CREATED,UPDATED) VALUES ('" . addslashes($this->request->data['usr_code']) . "','" . addslashes($user_pages_static) . "', NOW(), NOW())");
    			}
    		}
    		
            /*$this->Lead->query("INSERT INTO leads SET `USER_CODE`='" . $dataSave['USER_CODE'] . "', `REFER`='" . $originalRefer . "', `SOURCE_NAME`='" . $dataSave['SOURCE_NAME'] . "', `NAME`='" . $dataSave['NAME'] . "', `EMAIL`='" . $dataSave['EMAIL'] . "', `PHONE`='" . $dataSave['PHONE'] . "', `WANT_TO_START`='" . $dataSave['WANT_TO_START'] . "', `TYPE_OF_APP`='" . $dataSave['TYPE_OF_APP'] . "', `DESCRIPTION`='" . $dataSave['DESCRIPTION'] . "', `IP_ADDRESS`='" . $dataSave['IP_ADDRESS'] . "', `COUNTRY_CODE`='" . $data['countryCode'] . "', `COUNTRY`='" . $data['countryName'] . "', `STATE`='" . $data['regionName'] . "', `CITY`='" . $data['cityName'] . "', `ZIPCODE`='" . $data['zipCode'] . "', `LATITUDE`='" . $data['latitude'] . "', `LONGITUDE`='" . $data['longitude'] . "', `TIMEZONE`='" . $data['timeZone'] . "', `CREATED`='" . $dataSave['CREATED'] . "'");*/
		}
        $json = array('id' => 1, 'msg' => 'Lead Saved Successfully');
        echo json_encode($json);
        exit;
    }

    public function test_remotes() {
        echo php_uname('a');
        exit;
        echo $this->request->clientIp();
        echo "<pre>";
        print_r($this->getBrowser());
        exit;
    }
	
	public function updatepagesManual(){
		$this->loadModel('Leadinfo');
        $this->loadModel('Allpage');
        $this->loadModel('Pagevisit');
	
		$SelectData = $this->Leadinfo->query("SELECT * FROM `leadinfos`");
		
		foreach($SelectData as $key=>$value){
			$user_activity = json_decode($value['leadinfos']['USER_URLS'], true);
	        $user_activity = $user_activity['urls'];
			
			if (!empty($user_activity)) {
				foreach ($user_activity as $kk1 => $vv1) {
					if (!empty($vv1['url']) && !empty($vv1['lastmodified'])) {
					
						$selectPageIds = $this->Allpage->find('first', array('conditions' => array('pagename' => trim($vv1['url']))));
						
						if(empty($selectPageIds)) {
							$leadsData = $this->Lead->find('first', array('conditions' => array('Lead.USER_CODE' => $value['leadinfos']['USER_CODE']), 'order' => array('Lead.ID' => 'DESC')));
							if(count($leadsData) > 0){
								$source = $leadsData['Lead']['SOURCE_NAME'];
								$record['pagename'] = trim($vv1['url']);
								$record['sourcetitle'] = $source;
								$this->Allpage->create();
								$this->Allpage->save($record);
								$lastInsertID = $this->Allpage->getLastInsertId();
								$this->Pagevisit->query("INSERT INTO `pagevisits` SET user_code='" . $value['leadinfos']['USER_CODE'] . "', allpagesvisit='" . $lastInsertID . "', sourcesite='" . $source . "', created_at='" . $vv1['lastmodified'] . "'");
							}	
						} else {
							$this->Pagevisit->query("INSERT INTO `pagevisits` SET user_code='" . $value['leadinfos']['USER_CODE'] . "', allpagesvisit='" . $selectPageIds['Allpage']['id'] . "', sourcesite='" . $selectPageIds['Allpage']['sourcetitle'] . "', created_at='" . $vv1['lastmodified'] . "'");
						}
					}
				}
			}
		}
		exit;
	}
	
    public function updatepages() {
        $is_private = $this->Format->checkprivateip();
        if(!$is_private){
            $this->layout = "ajax";
            $this->loadModel('Leadinfo');
            $this->loadModel('Allpage');
            $this->loadModel('Pagevisit');

            $SelectData = $this->Leadinfo->query("SELECT * FROM `leadinfos` WHERE `USER_CODE`='" . $this->request->data['usr_code'] . "'");

            if (isset($SelectData) && count($SelectData) > 0) {
                $this->Leadinfo->query("UPDATE leadinfos SET USER_CODE='" . addslashes($this->request->data['usr_code']) . "',USER_URLS='" . addslashes($this->request->data['usr_pages']) . "',`UPDATED`=NOW() WHERE `USER_CODE`='" . $this->request->data['usr_code'] . "'");
            } else {
                $this->Leadinfo->query("REPLACE INTO leadinfos (USER_CODE,USER_URLS,CREATED,UPDATED) VALUES ('" . addslashes($this->request->data['usr_code']) . "','" . addslashes($this->request->data['usr_pages']) . "', NOW(), NOW())");
            }

            /* Track the pages visit details starts here */

            $user_activity = json_decode($this->request->data['usr_pages'], true);
            $user_activity = $user_activity['urls'];
            $user_activity_new = $user_activity;

            $user_activity_new_1d = Hash::extract($user_activity_new, "{n}.lastmodified"); //Make 1 dimensional array for checking this

            $selectLastId = $this->Pagevisit->query("SELECT DATE_FORMAT(max(created_at), '%Y/%c/%e %H:%i:%s') as LatestDate, sourcesite FROM pagevisits WHERE user_code='" . $this->request->data['usr_code'] . "'");

            $CurrentPage = $selectLastId[0][0]['LatestDate'];
            $MoreInsertArray = array_slice($user_activity_new, array_search($CurrentPage, $user_activity_new_1d) + 1);

    		//unset($valQuery);
           // $valQuery = array();
            if (!empty($MoreInsertArray)) {
                foreach ($MoreInsertArray as $kk1 => $vv1) {

                    if (!empty($vv1['url']) && !empty($vv1['lastmodified'])) {
                        
    					$selectPageIds = $this->Allpage->find('first', array('conditions' => array('pagename' => trim($vv1['url']))));
    					
                        if(empty($selectPageIds)){
                            $leadsData = $this->Lead->find('first', array('conditions' => array('Lead.USER_CODE' => $this->request->data['usr_code']), 'order' => array('Lead.ID' => 'DESC')));
                            $source = $leadsData['Lead']['SOURCE_NAME'];
                            $record['pagename'] = $vv1['url'];
                            $record['sourcetitle'] = $source;
                            $this->Allpage->create();
                            $this->Allpage->save($record);
                            $lastInsertID = $this->Allpage->getLastInsertId();
                            $this->Pagevisit->query("INSERT INTO `pagevisits` SET user_code='" . $this->request->data['usr_code'] . "', allpagesvisit='" . $lastInsertID . "', sourcesite='" . $source . "', created_at='" . $vv1['lastmodified'] . "'");
                        } else {
                            $this->Pagevisit->query("INSERT INTO `pagevisits` SET user_code='" . $this->request->data['usr_code'] . "', allpagesvisit='" . $selectPageIds['Allpage']['id'] . "', sourcesite='" . $selectPageIds['Allpage']['sourcetitle'] . "', created_at='" . $vv1['lastmodified'] . "'");
                        }
    					
    					
    					/*$selectPageIds = $this->Allpage->query("SELECT `id`,`sourcetitle` FROM `allpages` WHERE `pagename` = '" . $vv1['url'] . "'");
                        if (empty($selectPageIds)) {
                            $leadsData = $this->Lead->find('first', array('conditions' => array('Lead.USER_CODE' => $this->request->data['usr_code']), 'order' => array('Lead.ID' => 'DESC')));
                            $source = $leadsData['Lead']['SOURCE_NAME'];
                            $record['pagename'] = $vv1['url'];
                            $record['sourcetitle'] = $source;
                            $this->Allpage->create();
                            $this->Allpage->save($record);
                            $lastInsertID = $this->Allpage->getLastInsertId();
                            $valQuery[] = "INSERT INTO `pagevisits` SET user_code='" . $this->request->data['usr_code'] . "', allpagesvisit='" . $lastInsertID . "', sourcesite='" . $source . "', created_at='" . $vv1['lastmodified'] . "';";
                        } else {
                            $valQuery[] = "INSERT INTO `pagevisits` SET user_code='" . $this->request->data['usr_code'] . "', allpagesvisit='" . $selectPageIds[0]['allpages']['id'] . "', sourcesite='" . $selectPageIds[0]['allpages']['sourcetitle'] . "', created_at='" . $vv1['lastmodified'] . "';";
                        }*/
                    }
                }
                /*if (!empty($valQuery)) {
                    $this->Pagevisit->query(implode("", $valQuery));
                }*/
            }

            /* Track the pages visit details ends here */

        }
        $json = array('id' => 1, 'msg' => 'Lead Pages Saved Successfully');
        echo json_encode($json);
        exit;
    }

    public function updatepages_bak() {
        $this->layout = "ajax";
        $this->loadModel('Leadinfo');
        $this->loadModel('Allpage');
        $this->loadModel('Pagevisit');

        $SelectData = $this->Leadinfo->query("SELECT * FROM `leadinfos` WHERE `USER_CODE`='" . $this->request->data['usr_code'] . "'");

        if (isset($SelectData) && count($SelectData) > 0) {
            $this->Leadinfo->query("UPDATE leadinfos SET USER_CODE='" . addslashes($this->request->data['usr_code']) . "',USER_URLS='" . addslashes($this->request->data['usr_pages']) . "',`UPDATED`=NOW() WHERE `USER_CODE`='" . $this->request->data['usr_code'] . "'");

            $user_activity = json_decode($this->request->data['usr_pages'], true);
            $user_activity = $user_activity['urls'];

            $allUrls = '';
            $allUrlIds = '';
            $SaveSourceTitle = '';
            foreach ($user_activity as $key => $value) {
                $allUrls .= "'" . $value['url'] . "',";
                $selectPageIds = $this->Allpage->query("SELECT `id`,`sourcetitle` FROM `allpages` WHERE `pagename` = '" . $value['url'] . "'");
                $allUrlIds .= $selectPageIds[0]['allpages']['id'] . ",";
                $SaveSourceTitle = $selectPageIds[0]['allpages']['sourcetitle'];
            }
            /* $allUrls = substr($allUrls,0,-1);

              $selectPageIds = $this->Allpage->query("SELECT `id`,`sourcetitle` FROM `allpages` WHERE `pagename` IN (".$allUrls.")");

              $allUrlIds = '';$SaveSourceTitle = '';
              foreach($selectPageIds as $key1=>$selectPageId){
              $allUrlIds .= $selectPageId['allpages']['id'].",";
              $SaveSourceTitle = $selectPageId['allpages']['sourcetitle'];
              } */

            $allUrlIds = substr($allUrlIds, 0, -1);
            $selectPageVisit = $this->Pagevisit->query("SELECT * FROM pagevisits WHERE `user_code`='" . addslashes($this->request->data['usr_code']) . "'");
            if (isset($selectPageVisit) && count($selectPageVisit) > 0) {
                $updatePageVisit = $this->Pagevisit->query("UPDATE pagevisits SET `allpagesvisit`='" . $allUrlIds . "' WHERE `user_code`='" . addslashes($this->request->data['usr_code']) . "'");
            } else {
                $insertPageVisit = $this->Pagevisit->query("INSERT INTO pagevisits SET `user_code`='" . addslashes($this->request->data['usr_code']) . "', `allpagesvisit`='" . $allUrlIds . "', `sourcesite`='" . $SaveSourceTitle . "', `created_at`=NOW()");
            }
        } else {
            $this->Leadinfo->query("REPLACE INTO leadinfos (USER_CODE,USER_URLS,CREATED,UPDATED) VALUES ('" . addslashes($this->request->data['usr_code']) . "','" . addslashes($this->request->data['usr_pages']) . "', NOW(), NOW())");

            $user_activity = json_decode($this->request->data['usr_pages'], true);
            $user_activity = $user_activity['urls'];

            $allUrls = '';
            $allUrlIds = '';
            $SaveSourceTitle = '';
            foreach ($user_activity as $key => $value) {
                $allUrls .= "'" . $value['url'] . "',";
                $selectPageIds = $this->Allpage->query("SELECT `id`,`sourcetitle` FROM `allpages` WHERE `pagename` = '" . $value['url'] . "'");
                $allUrlIds .= $selectPageIds[0]['allpages']['id'] . ",";
                $SaveSourceTitle = $selectPageIds[0]['allpages']['sourcetitle'];
            }

            /* $allUrls = substr($allUrls,0,-1);
              $selectPageIds = $this->Allpage->query("SELECT `id`,`sourcetitle` FROM `allpages` WHERE `pagename` IN (".$allUrls.")");

              $allUrlIds = '';$SaveSourceTitle = '';
              foreach($selectPageIds as $key1=>$selectPageId){
              $allUrlIds .= $selectPageId['allpages']['id'].",";
              $SaveSourceTitle = $selectPageId['allpages']['sourcetitle'];
              } */

            $allUrlIds = substr($allUrlIds, 0, -1);

            $insertPageVisit = $this->Pagevisit->query("INSERT INTO pagevisits SET `user_code`='" . addslashes($this->request->data['usr_code']) . "', `allpagesvisit`='" . $allUrlIds . "', `sourcesite`='" . $SaveSourceTitle . "', `created_at`=NOW()");
        }
        $json = array('id' => 1, 'msg' => 'Lead Pages Saved Successfully');
        echo json_encode($json);
        exit;
    }

    public function leads() {
        if ($this->request->is('ajax')) {
            $this->layout = 'ajax';
            $filter_param = $this->request->query;

            $site_filter_val = trim(isset($filter_param['site_cookie_filter'])) ? $filter_param['site_cookie_filter'] : $filter_param['pros_site_cookie_filter'];
            $source_name = (isset($_COOKIE['leads_cookie_source_name']) && !empty($_COOKIE['leads_cookie_source_name'])) ? trim($_COOKIE['leads_cookie_source_name']) : $site_filter_val;
            $custom_filter_val_city = (isset($filter_param['city_custom_filter']) && !empty($filter_param['city_custom_filter'])) ? trim($filter_param['city_custom_filter']) : "";
            $custom_filter_val_country = (isset($filter_param['country_custom_filter']) && !empty($filter_param['country_custom_filter'])) ? trim($filter_param['country_custom_filter']) : "";
            $default_datatable_filter = (isset($_COOKIE['default_datatable_filter']) && !empty($_COOKIE['default_datatable_filter'])) ? trim($_COOKIE['default_datatable_filter']) : ((isset($filter_param['default_datatable_filter']) && !empty($filter_param['default_datatable_filter'])) ? trim($filter_param['default_datatable_filter']) : "");
            $sLimit = '';
            if (isset($filter_param['iDisplayStart']) && $filter_param['iDisplayLength'] != '-1') {
                $sLimit = 'LIMIT ' . (int) $filter_param['iDisplayStart'] . ', ' . (int) $filter_param['iDisplayLength'];
            }
            $aColumns = array('leads.NAME', 'leads.REFER', 'leads.IP_ADDRESS', 'leads.COUNTRY', 'leads.EMAIL', 'subscription_tracks.plan_id', 'leads.CREATED', 'subscription_tracks.last_login_date', 'leadinfos.UPDATED', 'leadinfos.USER_URLS', 'leadinfos.USER_URLS', 'leads.CITY', 'leads.SOURCE_NAME');
            /**
             * Ordering
             */
            $aOrderingRules = array();
            if (isset($filter_param['iSortCol_0'])) {
                $iSortingCols = intval($filter_param['iSortingCols']);
                for ($i = 0; $i < $iSortingCols; $i++) {
                    if ($filter_param['bSortable_' . intval($filter_param['iSortCol_' . $i])] == 'true') {
                        $aOrderingRules[] = "" . $aColumns[intval($filter_param['iSortCol_' . $i]) - 1] . " "
                                . ($filter_param['sSortDir_' . $i] === 'asc' ? 'asc' : 'desc');
                    }
                }
            }
            if (!empty($aOrderingRules)) {
                $sOrder = " ORDER BY " . implode(", ", $aOrderingRules);
            } else {
                $sOrder = "";
            }

            if (strpos($sOrder, 'leads.IP_ADDRESS') !== false) {
                $sOrder = str_ireplace('leads.IP_ADDRESS', 'INET_ATON(leads.IP_ADDRESS)', $sOrder);
            }
            if (strpos($sOrder, 'leadinfos.USER_URLS') !== false) {
                $sOrder = str_ireplace('leadinfos.USER_URLS', 'no_of_pages_visited', $sOrder);
            }

            $sWhere = "";
            if (isset($filter_param['sSearch']) && $filter_param['sSearch'] != '') {
                $sWhere = "WHERE (";
                for ($i = 0; $i < count($aColumns); $i++) {
                    if (isset($filter_param['bSearchable_' . $i]) && $filter_param['bSearchable_' . $i] == 'true') {
                        $sWhere .= '' . $aColumns[$i] . " LIKE '%" . mysql_real_escape_string($filter_param['sSearch']) . "%' OR ";
                    }
                }
                $sWhere = substr_replace($sWhere, '', -3);
                $sWhere .= ')';
            }

            /* Individual column filtering */
            for ($i = 0; $i < count($aColumns) + 2; $i++) {
                if (isset($filter_param['bSearchable_' . $i]) && $filter_param['bSearchable_' . $i] == "true" && $filter_param['sSearch_' . $i] != '') {
                    $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                    $sWhere .= $this->Query->filter_custom_column('leads', $i, $filter_param['sSearch_' . $i], $sWhere, 'leadinfos');
                }
            }

            /* Filtering according to option choosen from dropdown */
            if ($site_filter_val !== "All") {
                $displayData = $source_name;
                #$sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                #$sWhere .= $this->Query->custom_filter_dropdown("leads", "leadinfos", $sWhere, $source_name);
            } else {
                $displayData = 'All';
            }

            //Exclude Andolasoft Static IPS
            $sWhere .= ($sWhere == "") ? "WHERE `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')" : " AND `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')";

            if (isset($filter_param['signed_but_not_logged']) && !empty($filter_param['signed_but_not_logged'])) {
                $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                $sWhere .= "leads.CREATED IS NOT NULL AND (subscription_tracks.last_login_date IS NULL OR subscription_tracks.last_login_date = '0000-00-00 00:00:00')";
            }
            if (isset($filter_param['paid_but_not_logged']) && !empty($filter_param['paid_but_not_logged'])) {
                $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                $sWhere .= "leads.CREATED IS NOT NULL AND subscription_tracks.plan_id IN (5,10,12) AND (subscription_tracks.last_login_date IS NULL OR DATE(subscription_tracks.last_login_date) < DATE_SUB(curdate(), INTERVAL 1 WEEK))";
            }
            $sJoin = 'LEFT JOIN `leadinfos` ON (leads.USER_CODE = leadinfos.USER_CODE) LEFT JOIN `subscription_tracks` ON (subscription_tracks.email = leads.EMAIL)';
            $sColumns = "leads.ID,leads.USER_CODE,leads.REFER,leads.NAME,leads.IP_ADDRESS,leads.COUNTRY,leads.EMAIL,leads.TIMEZONE,leads.CREATED,leadinfos.UPDATED,ReturnUrlCount(urlRepeats(leadinfos.USER_URLS),'},{') as no_of_pages_visited,leads.CITY,leads.SOURCE_NAME,subscription_tracks.last_login_date,subscription_tracks.plan_id";
            $sQuery = "SELECT {$sColumns} FROM `leads` {$sJoin} {$sWhere} {$sOrder} {$sLimit}";
            /* this is require to count the datatable display record count starts */
            $sampleQry = "SELECT {$sColumns} FROM `leads` {$sJoin} {$sWhere} {$sOrder}";
            $sampleQry = str_replace('WHERE WHERE', 'WHERE', $sampleQry);
            $sampleQry = preg_replace('!\s+!', ' ', $sampleQry);
            /* this is require to count the datatable display record count ends */

            $aMembers = $this->Lead->query($sQuery);
            $aMembersCount = $this->Lead->query($sampleQry);

            $output = array(
                'sEcho' => intval($filter_param['sEcho']),
                'iTotalRecords' => $this->Lead->find('count', array(
                    'conditions' => array(
                        "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP'))
                    )
                )),
                'iTotalDisplayRecords' => count($aMembersCount),
                'displayData' => $displayData,
                'aaData' => array()
            );
            foreach ($aMembers as $iID => $aInfo) {
                $aItem = array("",
                    $aInfo['leads']['NAME'], $aInfo['leads']['REFER'], $aInfo['leads']['IP_ADDRESS'], $aInfo['leads']['COUNTRY'], $aInfo['leads']['EMAIL'], $aInfo['subscription_tracks']['plan_id'], date('Y-m-d H:i:s', strtotime($aInfo['leads']['CREATED'] . "+5 hours 30 minutes", 0))
                );
                (strtotime($aInfo['subscription_tracks']['last_login_date']) != 0 && !empty($aInfo['leads']['EMAIL']) && !empty($aInfo['leads']['NAME'])) ? array_push($aItem, date('Y-m-d H:i:s', strtotime($aInfo['subscription_tracks']['last_login_date'] . "+5 hours 30 minutes", 0))) : array_push($aItem, "");
                (strtotime($aInfo['leadinfos']['UPDATED']) != 0) ? array_push($aItem, date('Y-m-d H:i:s', strtotime($aInfo['leadinfos']['UPDATED'] . "+5 hours 30 minutes", 0))) : array_push($aItem, intval($aInfo[0]['no_of_pages_visited']));
                array_push($aItem, intval($aInfo[0]['no_of_pages_visited']));
                array_push($aItem, "");
                array_push($aItem, $aInfo['leads']['CITY']);
                array_push($aItem, $aInfo['leads']['SOURCE_NAME']);
                $aItem['DT_RowId'] = $aInfo['leads']['ID'];
                $aItem['USER_CODE'] = $aInfo['leads']['USER_CODE'];
                $aItem = $this->Format->IsNullOrEmptyString($aItem);

                $getLeadDetails = $this->Lead->find('all', array('conditions' => array('Leadinfo.USER_CODE' => $aInfo['leads']['USER_CODE'], 'Lead.ID' => $aInfo['leads']['ID'], "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP')))));
                $user_code = (!empty($getLeadDetails)) ? $getLeadDetails[0]['Leadinfo']['USER_CODE'] : "";
                $user_activity = (isset($getLeadDetails[0]['Leadinfo']['USER_URLS']) && !empty($getLeadDetails[0]['Leadinfo']['USER_URLS'])) ? json_decode($getLeadDetails[0]['Leadinfo']['USER_URLS'], true) : "";

                $LastFiveUrlVisits = '';
                $urlCount = 1;
                if (is_array($user_activity) && count($user_activity) > 0) {
                    $user_activity = array_reverse($user_activity['urls']);
                    foreach ($user_activity as $key => $value) {
                        if ($urlCount < 11) {
                            $LastFiveUrlVisits .= "<p>" . $value['url'] . "</p>";
                        }
                        $urlCount++;
                    }
                }
                $aItem['LeadActivity'] = $LastFiveUrlVisits;

                $output['aaData'][] = $aItem;
            }
            echo json_encode($output);
            exit;
        }
    }

    function leaddetails() {
        if ($this->request->is('ajax')) {
            $this->loadModel('Trackevent');
            $this->loadModel('Lead');
            $this->loadModel('SubscriptionTrack');
            $cook_arr = json_decode($_COOKIE['repo_cookie_obj_cook'], true);
            $id = $cook_arr['usr_track'];
            $leadId = $cook_arr['lead_id'];
            $getLeadDetails = $this->Lead->find('all', array('conditions' => array('Leadinfo.USER_CODE' => $id, 'Lead.ID' => $leadId, "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP')))));
            $user_code = $getLeadDetails[0]['Leadinfo']['USER_CODE'];
            $user_activity = json_decode($getLeadDetails[0]['Leadinfo']['USER_URLS'], true);
            $user_activity = array_reverse($user_activity['urls']);
            $visitedDateArr = array();
            foreach ($user_activity as $k => $v) {
                $date_time_arr = explode(" ", $v['lastmodified']);
                if (!empty($visitedDateArr[$date_time_arr[0]])) {
                    array_push($visitedDateArr[$date_time_arr[0]], $v['url']);
                } else {
                    $visitedDateArr[$date_time_arr[0]] = array();
                    array_push($visitedDateArr[$date_time_arr[0]], $v['url']);
                }
            }
            foreach ($visitedDateArr as $key => $value) {
                foreach ($value as $arr => $val) {
                    if (basename($val) == 'www.orangescrum.com' || basename($val) == 'www.andolasoft.com' || basename($val) == 'www.orangescrum.org' || basename($val) == 'wakeupsales.org') {
                        $pageName = "Home";
                    } else {
                        $pageName = basename($val);
                        if (strpos($pageName, ".php") !== false) {
                            $pageName = substr($pageName, 0, -4);
                        } else {
                            $pageName = $pageName;
                        }
                    }
                    $visitedDateArr[$key][$arr] = $visitedDateArr[$key][$arr] . "@@@@" . $pageName;
                }
            }
            $getLeadDetails[0]['Lead']['brwser'] = Common::getBrowsers();
            $getLeadDetails[0]['Lead']['time_zone'] = (count(explode(":", $getLeadDetails[0]['Lead']['TIMEZONE'])) > 1) ? Inflector::humanize(Common::getTz_from_offset($getLeadDetails[0]['Lead']['TIMEZONE'])) : "N/A";
            $getLeadDetails[0]['Lead']['def_language'] = Common::getDefaultLanguage();
            $getLeadDetails[0]['Lead']['getOS'] = Common::getOS();
            $data['usersActivities'] = $visitedDateArr;
            $data['getLeadDetails'] = $getLeadDetails[0]['Lead'];
            $data['getLeadActivity'] = $user_activity;
            $data['getLeadActivityCount'] = count($user_activity);
            $data['getLeadActivityRev'] = array_reverse($user_activity);
            $data['getUserCode'] = $user_code;
            $data['leadId'] = $leadId;

            /* Code for getting track events starts here */
            if (isset($getLeadDetails[0]['Lead']['EMAIL']) && $getLeadDetails[0]['Lead']['EMAIL'] != '') {
                $selectAllTrackEvents = $this->Trackevent->query("SELECT * FROM trackevents WHERE email='" . $getLeadDetails[0]['Lead']['EMAIL'] . "' ORDER BY created_at DESC");
                $EventDateArr = array();
                foreach ($selectAllTrackEvents as $k1 => $v1) {
                    $date_time_arr_event = explode(" ", $v1['trackevents']['created_at']);
                    if (!empty($EventDateArr[$date_time_arr_event[0]])) {
                        array_push($EventDateArr[$date_time_arr_event[0]], $v1['trackevents']);
                    } else {
                        $EventDateArr[$date_time_arr_event[0]] = array();
                        array_push($EventDateArr[$date_time_arr_event[0]], $v1['trackevents']);
                    }
                }
                $data['EventDateArr'] = $EventDateArr;
                $data['CountEventDateArr'] = count($EventDateArr);
            }
            $resp_array = array('status' => true, 'data' => $data);
            echo json_encode($resp_array);
            exit;
        }
        $this->set('referer',$this->request->referer());
    }

    function prospects() {
        if ($this->request->is('ajax')) {

            $this->layout = 'ajax';
            $filter_param = $this->request->query;
            $site_filter_val = trim($filter_param['pros_site_cookie_filter']);
            $source_name = (isset($_COOKIE['pros_cookie_source_name']) && !empty($_COOKIE['pros_cookie_source_name'])) ? trim($_COOKIE['pros_cookie_source_name']) : $site_filter_val;
            $custom_filter_val_city = (isset($filter_param['city_custom_filter']) && !empty($filter_param['city_custom_filter'])) ? trim($filter_param['city_custom_filter']) : "";
            $custom_filter_val_country = (isset($filter_param['country_custom_filter']) && !empty($filter_param['country_custom_filter'])) ? trim($filter_param['country_custom_filter']) : "";
            $default_datatable_filter = (isset($_COOKIE['default_datatable_filter']) && !empty($_COOKIE['default_datatable_filter'])) ? trim($_COOKIE['default_datatable_filter']) : ((isset($filter_param['default_datatable_filter']) && !empty($filter_param['default_datatable_filter'])) ? trim($filter_param['default_datatable_filter']) : "");

            $sLimit = '';
            if (isset($filter_param['iDisplayStart']) && $filter_param['iDisplayLength'] != '-1') {
                $sLimit = 'LIMIT ' . (int) $filter_param['iDisplayStart'] . ', ' . (int) $filter_param['iDisplayLength'];
            }
            $aColumns = array('prospects.IP', 'prospects.COUNTRY', 'prospects.REFER', 'prospects.SOURCE_NAME', 'prospects.CREATED', 'prospectvisits.UPDATED', 'prospectvisits.URLVISIT');
            /**
             * Ordering
             */
            $aOrderingRules = array();
            if (isset($filter_param['iSortCol_0'])) {
                $iSortingCols = intval($filter_param['iSortingCols']);
                for ($i = 0; $i < $iSortingCols; $i++) {
                    if ($filter_param['bSortable_' . intval($filter_param['iSortCol_' . $i])] == 'true') {
                        $aOrderingRules[] = "" . $aColumns[intval($filter_param['iSortCol_' . $i]) - 1] . " "
                                . ($filter_param['sSortDir_' . $i] === 'asc' ? 'asc' : 'desc');
                    }
                }
            }
            if (!empty($aOrderingRules)) {
                $sOrder = " ORDER BY " . implode(", ", $aOrderingRules);
            } else {
                $sOrder = "";
            }

            if (strpos($sOrder, 'prospects.IP') !== false) {
                $sOrder = str_ireplace('prospects.IP', 'INET_ATON(prospects.IP)', $sOrder);
            }

            if (strpos($sOrder, 'prospectvisits.URLVISIT') !== false) {
                $sOrder = str_ireplace('prospectvisits.URLVISIT', 'no_of_pages_visited', $sOrder);
            }
//echo '1';exit;
            $sWhere = "";
            if (isset($filter_param['sSearch']) && $filter_param['sSearch'] != '') {
                $sWhere = "WHERE (";
                for ($i = 0; $i < count($aColumns); $i++) {
                    if (isset($filter_param['bSearchable_' . $i]) && $filter_param['bSearchable_' . $i] == 'true') {
                        $sWhere .= '' . $aColumns[$i] . " LIKE '%" . mysql_real_escape_string($filter_param['sSearch']) . "%' OR ";
                    }
                }
                $sWhere = substr_replace($sWhere, '', -3);
                $sWhere .= ')';
            }

            /* Individual column filtering */
            for ($i = 0; $i < count($aColumns); $i++) {
                if (isset($filter_param['bSearchable_' . $i]) && $filter_param['bSearchable_' . $i] == "true") {
                    if (!empty($filter_param['sSearch_' . $i])) {
                        $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                        $sWhere .= $this->Query->filter_custom_column('prospects', $i, $filter_param['sSearch_' . $i], $sWhere, 'prospectvisits');
                    }
                }
            }

            // Dropdown filter based on Source Select Box
            if ($source_name !== "All") {
                $displayData = $source_name;
                $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                $sWhere .= $this->Query->custom_filter_dropdown("prospects", "prospectvisits", $sWhere, $source_name);
            } else {
                $displayData = 'All';
            }
//echo '1';exit;
            //Custom Filters For Known & Unknown City
            if ($custom_filter_val_city) {
                $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                $sWhere .= $this->Query->custom_filter_city($sWhere, $custom_filter_val_city, 'prospects');
            }

            //Exclude Andolasoft Static IPS
            $sWhere .= ($sWhere == "") ? "WHERE prospects.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')" : " AND prospects.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')";

            $sJoin = 'LEFT JOIN `prospectvisits` ON prospects.ID = prospectvisits.PROSPECTID';
            $sColumns = "prospects.ID,prospects.IP, prospects.COUNTRY, prospects.REFER, prospects.SOURCE_NAME, prospects.CREATED,prospects.TIMEZONE,prospectvisits.UPDATED,ReturnUrlCount(prospectvisits.URLVISIT,'@#$@#$') as no_of_pages_visited";

            $sQuery = "SELECT {$sColumns} FROM `prospects` {$sJoin} {$sWhere} {$sOrder} {$sLimit}";
            $sQuery = str_replace('WHERE WHERE', 'WHERE', $sQuery);
            $sQuery = preg_replace('!\s+!', ' ', $sQuery);

            /* this is require to count the datatable display record count starts */
//            $sampleQry = "SELECT {$sColumns} FROM `prospects` {$sJoin} {$sWhere} {$sOrder}";
            $sampleQry = "SELECT count(*) as cnt FROM `prospects` {$sJoin} {$sWhere} {$sOrder}";
            $sampleQry = str_replace('WHERE WHERE', 'WHERE', $sampleQry);
            $sampleQry = preg_replace('!\s+!', ' ', $sampleQry);
            /* this is require to count the datatable display record count ends */
//            pr($sQuery);
            $this->loadModel('Prospect');
            $aMembers = $this->Prospect->query($sQuery);
            
//            print_r($aMembers);exit;
            $aMembersCount = $this->Prospect->query($sampleQry);
            $aMembersCount = $aMembersCount[0][0]['cnt'];

//            print_r($aMembersCount);exit;
            $output = array(
                'sEcho' => intval($filter_param['sEcho']),
                'iTotalRecords' => $this->Prospect->find('count'),
                'iTotalDisplayRecords' => $aMembersCount,
                'displayData' => $displayData,
                'aaData' => array()
            );
//            pr($output);exit;
            $view = new View($this);
            $format = $view->loadHelper('Format');


            foreach ($aMembers as $iID => $aInfo) {
                $aItem = array("",
                    $aInfo['prospects']['IP'], $aInfo['prospects']['COUNTRY'], $aInfo['prospects']['REFER'], $aInfo['prospects']['SOURCE_NAME'], date('Y-m-d H:i:s', strtotime($aInfo['prospects']['CREATED'] . "+5 hours 30 minutes", 0))
                );
                (strtotime($aInfo['prospectvisits']['UPDATED']) != 0) ? array_push($aItem, date('Y-m-d H:i:s', strtotime($aInfo['prospectvisits']['UPDATED'] . "+5 hours 30 minutes", 0))) : array_push($aItem, "NA");
                array_push($aItem, intval($aInfo[0]['no_of_pages_visited']));
                array_push($aItem, intval($aInfo[0]['no_of_pages_visited']));
                $aItem['DT_RowId'] = $aInfo['prospects']['ID'];
                $aItem['created_mom'] = strtotime($aInfo['prospects']['CREATED'] . "+5 hours 30 minutes", 0) * 1000;
                $aItem['updated_momup'] = strtotime($aInfo['prospectvisits']['UPDATED'] . "+5 hours 30 minutes", 0) * 1000;
                $aItem['def_timeZone'] = trim($format->getTz_from_offset($aInfo['prospects']['TIMEZONE']));
                $aItem = $this->Format->IsNullOrEmptyString($aItem);
                $output['aaData'][] = $aItem;
            }
            echo json_encode($output);
            exit;
        }
    }

    public function prospectsdetail() {
        if ($this->request->is('ajax')) {
            $prospectIdobj = json_decode($_COOKIE['pros_det_cookie_obj'], true);
            $prospectId = $prospectIdobj['prospectId'];
            $this->loadModel('Prospectvisit');
            $getProspectDetails = $this->Prospectvisit->find('all', array('conditions' => array('Prospectvisit.PROSPECTID' => $prospectId)));
            $user_activity = explode("@#$@#$", $getProspectDetails[0]['Prospectvisit']['URLVISIT']);
            $user_activity = array_reverse($user_activity);
            $prospectDateArr = array();
            foreach ($user_activity as $k => $v) {
                $prospect_activity = json_decode($v, true);
                $date_time_arr = explode(" ", $prospect_activity['visitdate']);
                if (!empty($prospectDateArr[$date_time_arr[0]])) {
                    array_push($prospectDateArr[$date_time_arr[0]], $prospect_activity['urlname']);
                } else {
                    $prospectDateArr[$date_time_arr[0]] = array();
                    array_push($prospectDateArr[$date_time_arr[0]], $prospect_activity['urlname']);
                }
                $mainProsArr[] = $prospect_activity;
            }

            foreach ($prospectDateArr as $key => $value) {
                foreach ($value as $arr => $val) {
                    if (basename($val) == 'www.orangescrum.com' || basename($val) == 'www.andolasoft.com' || basename($val) == 'www.orangescrum.org' || basename($val) == 'wakeupsales.org') {
                        $pageName = "Home";
                    } else {
                        $pageName = basename($val);
                        if (strpos($pageName, ".php") !== false) {
                            $pageName = substr($pageName, 0, -4);
                        } else {
                            $pageName = $pageName;
                        }
                    }
                    $prospectDateArr[$key][$arr] = $prospectDateArr[$key][$arr] . "@@@@" . $pageName;
                }
            }
            $getProspectDetails[0]['Prospect']['time_zone'] = (count(explode(":", $getProspectDetails[0]['Prospect']['TIMEZONE'])) > 1) ? Inflector::humanize(Common::getTz_from_offset($getProspectDetails[0]['Prospect']['TIMEZONE'])) : "N/A";
            $getProspectDetails[0]['Prospect']['lat_lng'] = $getProspectDetails[0]['Prospect']['LATITUDE'] . ", " . $getProspectDetails[0]['Prospect']['LONGITUDE'];
            if ($getProspectDetails[0]['Prospect']['CITY'] != "-" && $getProspectDetails[0]['Prospect']['ZIPCODE'] != "-" && $getProspectDetails[0]['Prospect']['STATE'] != "-" && $getProspectDetails[0]['Prospect']['COUNTRY'] != "-") {
                $getProspectDetails[0]['Prospect']['formatted_address'] = $getProspectDetails[0]['Prospect']['CITY'] . ", " . $getProspectDetails[0]['Prospect']['ZIPCODE'] . "<br/>" . $getProspectDetails[0]['Prospect']['STATE'] . ", " . $getProspectDetails[0]['Prospect']['COUNTRY'];
            } else if ($getProspectDetails[0]['Prospect']['CITY'] != "-" && $getProspectDetails[0]['Prospect']['ZIPCODE'] == "-" && $getProspectDetails[0]['Prospect']['STATE'] != "-" && $getProspectDetails[0]['Prospect']['COUNTRY'] != "-") {
                $getProspectDetails[0]['Prospect']['formatted_address'] = $getProspectDetails[0]['Prospect']['CITY'] . "<br/>" . $getProspectDetails[0]['Prospect']['STATE'] . ", " . $getProspectDetails[0]['Prospect']['COUNTRY'];
            } else {
                $getProspectDetails[0]['Prospect']['formatted_address'] = "N/A";
            }
            $data['prospectsActivities'] = $prospectDateArr;
            $data['getProspectDetails'] = $getProspectDetails;
            $data['getProspectActivity'] = $mainProsArr;
            $data['prospectId'] = $prospectId;
            $data['TotalURLCountDetails'] = count($mainProsArr);
            $resp_array = array('status' => true, 'data' => $data);
            echo json_encode($resp_array);
            exit;
        }
    }

    public function nextprev() {
        if ($this->request->is('ajax')) {
            $data = $this->request->data;
            if ($data['type'] == "prev") {
                $options['conditions'] = array('Lead.id >' => $data['leadId']);
                $options['order'] = array('Lead.id ASC');
                $option_last['order'] = array('Lead.id ASC');
            } else if ($data['type'] == "next") {
                $options['conditions'] = array('Lead.id <' => $data['leadId']);
                $options['order'] = array('Lead.id DESC');
                $option_last['order'] = array('Lead.id DESC');
            }
            $options['conditions']['NOT'] = $option_last['conditions']['NOT'] = array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP'));
            $options['fields'] = $option_last['fields'] = array('Lead.id', 'Lead.user_code');
            $options['limit'] = $option_last['limit'] = 1;
            $selectValue = $this->Lead->find('all', $options);
            $selectLastValue = $this->Lead->find('all', $option_last);
            if (is_array($selectValue) && count($selectValue) > 0) {
                $resp = array('userCode' => $selectValue[0]['Lead']['user_code'], 'leadId' => $selectValue[0]['Lead']['id']);
            } else {
                $resp = array('userCode' => $selectLastValue[0]['Lead']['user_code'], 'leadId' => $selectLastValue[0]['Lead']['id']);
            }
            echo json_encode($resp);
            exit;
        }
    }

    public function nextprevprospect($id = NULL, $action = NULL) {
        if ($this->request->is('ajax')) {
            $data = $this->request->data;
            $id = $data['prospectId'];
            $this->loadModel('Prospect');
            if ($data['type'] == "prev") {
                $selectValue = $this->Prospect->query("SELECT `ID` FROM `prospects` WHERE `ID`>'" . $id . "' ORDER BY `ID` ASC LIMIT 1");
                $selectLastValue = $this->Prospect->query("SELECT `ID` FROM `prospects` ORDER BY `ID` ASC LIMIT 1");
            } else if ($data['type'] == "next") {
                $selectValue = $this->Prospect->query("SELECT `ID` FROM `prospects` WHERE `ID`<'" . $id . "' ORDER BY `ID` DESC LIMIT 1");
                $selectLastValue = $this->Prospect->query("SELECT `ID` FROM `prospects` ORDER BY `ID` DESC LIMIT 1");
            }

            if (is_array($selectValue) && count($selectValue) > 0) {
                $userID = $selectValue[0]['prospects']['ID'];
                $resp = array('prospectId' => $userID);
            } else {
                $userID = $selectLastValue[0]['prospects']['ID'];
                $resp = array('prospectId' => $userID);
            }
            echo json_encode($resp);
            exit;
        }
    }

    public function get_cities() {
        $this->loadModel('Prospect');
        $city_data = $this->Prospect->find('all', array('fields' => array('DISTINCT CITY'), 'conditions' => array('Prospect.CITY !=' => '', 'Prospect.CITY !=' => '-'), 'order' => array('Prospect.CITY ASC')));
        $cities = array();
        foreach ($city_data as $key => $value) {
            $cities[] = $value['Prospect']['CITY'];
        }
        print(json_encode($cities));
        exit;
    }

    public function get_countries() {
        $this->loadModel('Prospect');
        $country_data = $this->Prospect->find('all', array('fields' => array('DISTINCT COUNTRY'), 'conditions' => array('Prospect.COUNTRY !=' => '', 'Prospect.COUNTRY !=' => '-'), 'order' => array('Prospect.COUNTRY ASC')));
        $countries = array();
        foreach ($country_data as $key => $value) {
            $countries[] = $value['Prospect']['COUNTRY'];
        }
        print(json_encode($countries));
        exit;
    }

    function send_email_to_leads() {
        if ($this->request->is('ajax')) {
            $data = $this->request->data;
            $email_arr = explode(',', $data['EmailTemplate']['to']);
            foreach ($email_arr as $email_id) {
                $email_description = $data['EmailTemplate']['description'];
                $this->Email->delivery = 'smtp';
                $this->Email->to = $email_id;
                $this->Email->from = 'admin@leadtrack.com';
                $this->Email->subject = $data['EmailTemplate']['subject'];
                $this->Email->sendAs = 'html';
                try {
                    $is_mail_send = true;
                    $this->Sendgrid->sendgridsmtp($this->Email, $email_description);
                } catch (Exception $e) {
                    $is_mail_send = false;
                    echo 'Exception : ', $e->getMessage(), "\n";
                }
            }
            if ($is_mail_send) {
                $res = array('status' => true);
            } else {
                $res = array('status' => false);
            }
            echo json_encode($res);
        }exit;
    }

    function visited_leads() {
        if ($this->request->is('ajax')) {
            $this->layout = 'ajax';
            $filter_param = $this->request->query;
            $site_filter_val = trim($filter_param['site_cookie_filter']);
            $source_name = (isset($_COOKIE['cookie_source_name']) && !empty($_COOKIE['cookie_source_name'])) ? trim($_COOKIE['cookie_source_name']) : $site_filter_val;

            $sLimit = '';
            if (isset($filter_param['iDisplayStart']) && $filter_param['iDisplayLength'] != '-1') {
                $sLimit = 'LIMIT ' . (int) $filter_param['iDisplayStart'] . ', ' . (int) $filter_param['iDisplayLength'];
            }
            //$aColumns = array('leads.NAME', 'leads.REFER', 'leads.IP_ADDRESS', 'leads.COUNTRY', 'leads.EMAIL', 'leads.CREATED', 'leadinfos.UPDATED', 'leadinfos.USER_URLS');			
            $aColumns = array("leads.NAME", "leads.EMAIL", "leadinfos.UPDATED", "ReturnUrlCount(urlRepeats(leadinfos.USER_URLS),'},{')", "leads.CREATED", "leads.ID", "leads.USER_CODE");

            /**
             * Ordering
             */
            $aOrderingRules = array();
            if (isset($filter_param['iSortCol_0'])) {
                $iSortingCols = intval($filter_param['iSortingCols']);
                for ($i = 0; $i < $iSortingCols; $i++) {
                    if ($filter_param['bSortable_' . intval($filter_param['iSortCol_' . $i])] == 'true') {
                        #if (intval($filter_param['iSortCol_0']) !== 8) {
                        $aOrderingRules[] = "" . $aColumns[intval($filter_param['iSortCol_' . $i]) - 1] . " "
                                . ($filter_param['sSortDir_' . $i] === 'asc' ? 'asc' : 'desc');
                        #}
                    }
                }
            }

            if (!empty($aOrderingRules)) {
                $sOrder = " ORDER BY " . implode(", ", $aOrderingRules);
            } else {
                $sOrder = "";
            }

            if (strpos($sOrder, 'leads.IP_ADDRESS') !== false) {
                $sOrder = str_ireplace('leads.IP_ADDRESS', 'INET_ATON(leads.IP_ADDRESS)', $sOrder);
            }

            $sWhere = "";
            if (isset($filter_param['sSearch']) && $filter_param['sSearch'] != '') {
                $sWhere = "WHERE (";
                for ($i = 0; $i < count($aColumns); $i++) {
                    if (isset($filter_param['bSearchable_' . $i]) && $filter_param['bSearchable_' . $i] == 'true') {
                        $sWhere .= '' . $aColumns[$i] . " LIKE '%" . mysql_real_escape_string($filter_param['sSearch']) . "%' OR ";
                    }
                }
                $sWhere = substr_replace($sWhere, '', -3);
                $sWhere .= ')';
            }
            /* Individual column filtering */
            for ($i = 0; $i < count($aColumns); $i++) {
                if (isset($filter_param['bSearchable_' . $i]) && $filter_param['bSearchable_' . $i] == "true" && $filter_param['sSearch_' . $i] != '') {
                    $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                    $sWhere .= $aColumns[$i] . " LIKE '%" . mysql_real_escape_string($filter_param['sSearch_' . $i]) . "%' ";
                }
            }

            /* Filtering according to option choosen from dropdown */
            if ($source_name !== "All") {
                $displayData = $source_name;
                $sWhere .= ($sWhere == "") ? "WHERE " : " AND ";
                switch ($source_name) {
                    case 'www.orangescrum.com':
                        $sWhere .= "leads.SOURCE_NAME = 'www.orangescrum.com'";
                        break;
                    case 'www.orangescrum.org':
                        $sWhere .= "leads.SOURCE_NAME = 'www.orangescrum.org'";
                        break;
                    case 'www.andolasoft.com':
                        $sWhere .= "leads.SOURCE_NAME = 'www.andolasoft.com'";
                        break;
                    case 'blog.andolasoft.com':
                        $sWhere .= "leads.SOURCE_NAME = 'blog.andolasoft.com'";
                        break;
                    case 'today':
                        $sWhere .= "(leads.CREATED LIKE '%" . date('Y-m-d') . "%' OR leadinfos.UPDATED LIKE '%" . date('Y-m-d') . "%')";
                        break;
                    case 'sevendays':
                        $sWhere .= "(DATE(leads.CREATED) > '" . date('Y-m-d', strtotime('-7 days')) . "' OR DATE(leadinfos.UPDATED) > '" . date('Y-m-d', strtotime("-7 days")) . "')";
                        break;
                    default:
                        $sWhere = "";
                        break;
                }
            } else {
                $displayData = 'All';
            }
            //Exclude Andolasoft Static IPS
            $sWhere .= ($sWhere == "") ? "WHERE leads.IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')" : " AND leads.IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')";

            $sJoin = 'LEFT JOIN `leadinfos` ON leads.USER_CODE = leadinfos.USER_CODE';
            $sColumns = "leads.NAME,leads.EMAIL,leadinfos.UPDATED,ReturnUrlCount(urlRepeats(leadinfos.USER_URLS),'},{') as no_of_pages_visited,leadinfos.CREATED,leads.ID,leads.USER_CODE";
            $sQuery = "SELECT {$sColumns} FROM `leads` {$sJoin} {$sWhere} HAVING no_of_pages_visited > 10 {$sOrder} {$sLimit}";


            /* this is require to count the datatable display record count starts */
            $sampleQry = "SELECT {$sColumns} FROM `leads` {$sJoin} {$sWhere} HAVING no_of_pages_visited > 10 {$sOrder}";
            $sampleQry = str_replace('WHERE WHERE', 'WHERE', $sampleQry);
            $sampleQry = preg_replace('!\s+!', ' ', $sampleQry);
            /* this is require to count the datatable display record count ends */


            $aMembers = $this->Lead->query($sQuery);
            $aMembersCount = $this->Lead->query($sampleQry);
            $output = array(
                'sEcho' => intval($filter_param['sEcho']),
                'iTotalRecords' => $this->Lead->find('count', array(
                    'conditions' => array(
                        "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP'))
                    )
                )),
                'iTotalDisplayRecords' => count($aMembersCount),
                'displayData' => $displayData,
                'aaData' => array()
            );
            $view = new View($this);
            $format = $view->loadHelper('Format');
            foreach ($aMembers as $iID => $aInfo) {
                $aItem = array("",
                    $aInfo['leads']['NAME'], $aInfo['leads']['EMAIL']
                );

                $lastActivityDate = date("m/d/Y H:i:s", strtotime($aInfo['leadinfos']['UPDATED'] . "+5 hours 30 minutes", 0));

                (strtotime($aInfo['leadinfos']['UPDATED']) != 0) ? array_push($aItem, $lastActivityDate) : array_push($aItem, intval($aInfo[0]['no_of_pages_visited']));
                array_push($aItem, intval($aInfo[0]['no_of_pages_visited']));
                if ($aInfo['leads']['ID'] == "") {
                    $aItem['DT_RowId'] = "";
                } else {
                    $aItem['DT_RowId'] = $aInfo['leads']['ID'];
                }

                $aItem['USER_CODE'] = $aInfo['leads']['USER_CODE'];
                $aItem['downloaded_date'] = date("m/d/Y H:i:s", strtotime($aInfo['leadinfos']['CREATED'] . "+5 hours 30 minutes", 0));
                $aItem = $this->Format->IsNullOrEmptyString($aItem);

                $getLeadDetails = $this->Lead->find('all', array('conditions' => array('Leadinfo.USER_CODE' => $aInfo['leads']['USER_CODE'], 'Lead.ID' => $aInfo['leads']['ID'], "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP')))));
                $user_code = $getLeadDetails[0]['Leadinfo']['USER_CODE'];
                $user_activity = json_decode($getLeadDetails[0]['Leadinfo']['USER_URLS'], true);
                $user_activity = array_reverse($user_activity['urls']);

                $LastFiveUrlVisits = '';
                $urlCount = 1;
                foreach ($user_activity as $key => $value) {
                    if ($urlCount < 11) {
                        $LastFiveUrlVisits .= "<p>" . $value['url'] . "</p>";
                    }
                    $urlCount++;
                }

                $aItem['LeadActivity'] = $LastFiveUrlVisits;

                $output['aaData'][] = $aItem;
            }

            echo json_encode($output);
            exit;
        }
    }

    function send_automated_email() {
        $this->layout = 'ajax';
        $allLeadsData = $this->Lead->query("SELECT leads.*, ReturnUrlCount(urlRepeats(leadinfos.USER_URLS),'},{') as no_of_pages_visited FROM leads, leadinfos WHERE `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') HAVING no_of_pages_visited>10 LIMIT 50");
        foreach ($allLeadsData as $k => $v) {
            if (!empty($v['leads']['EMAIL'])) {
                $message = "Hi " . $v['leads']['NAME'];
                $this->Email->delivery = 'smtp';
                $this->Email->to = $v['leads']['EMAIL'];
                $this->Email->from = 'admin@leadtrack.com';
                $this->Email->subject = 'Reminder Mail';
                $this->Email->sendAs = 'html';
                try {
                    $this->Sendgrid->sendgridsmtp($this->Email, $message);
                } catch (Exception $e) {
                    echo 'Exception : ', $e->getMessage(), "\n";
                }
            }
        }exit;
    }

    function pathreport() {
        $this->loadModel('Allpage');
        $this->loadModel('Pagevisit');
        $this->loadModel('Leadinfo');
        if (isset($this->request->data) && !empty($this->request->data) && isset($this->request->data['startDate'], $this->request->data['endDate']) && $this->request->data['startDate'] != '' && $this->request->data['endDate']) {
            $fromPathId = $this->request->data['fromSitePath'];
            $toPathId = $this->request->data['toSitePath'];
            $getAllPageVisitDetails = $this->Pagevisit->query("select pagevisits.user_code, group_concat(`allpagesvisit` ORDER BY created_at ASC) as `allpagevisits`, group_concat(`created_at` ORDER BY created_at ASC) as `allpagevisitsdates` from pagevisits RIGHT JOIN leads ON (leads.USER_CODE = pagevisits.user_code) AND (leads.IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')) AND (`leads`.`SOURCE_NAME` = '" . $this->request->data['souceSites'] . "') WHERE DATE(`created_at`)>='" . $this->request->data['startDate'] . "' AND DATE(`created_at`)<='" . $this->request->data['endDate'] . "' AND `sourcesite`='" . $this->request->data['souceSites'] . "' group by user_code");
            $selectAllPages = $this->Allpage->query("SELECT * FROM allpages WHERE sourcetitle='" . $this->request->data['souceSites'] . "' ORDER BY pagename ASC");
            $this->set('fromdateset', $this->request->data['startDate']);
            $this->set('todateset', $this->request->data['endDate']);
            $this->set('sourcevalset', $this->request->data['souceSites']);
        } else {
            $getAllPageVisitDetails = $this->Pagevisit->query("select pagevisits.user_code, group_concat(`allpagesvisit` ORDER BY created_at ASC) as `allpagevisits`, group_concat(`created_at` ORDER BY created_at ASC) as `allpagevisitsdates` from pagevisits RIGHT JOIN leads ON (leads.USER_CODE = pagevisits.user_code) AND (leads.IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')) AND (`leads`.`SOURCE_NAME` = 'www.orangescrum.org') WHERE DATE(`created_at`)>=curdate() AND DATE(`created_at`)<=curdate() AND `sourcesite`='www.orangescrum.org' group by user_code");
            $selectAllPages = $this->Allpage->query("SELECT * FROM allpages WHERE sourcetitle='www.orangescrum.org' ORDER BY pagename ASC");
        }
        foreach ($selectAllPages as $k1 => $v1) {
            if (basename($v1['allpages']['pagename']) == 'www.orangescrum.com' || basename($v1['allpages']['pagename']) == 'www.andolasoft.com' || basename($v1['allpages']['pagename']) == 'www.orangescrum.org' || basename($v1['allpages']['pagename']) == 'wakeupsales.org') {
                $pageName = "Home";
            } else {
                $pageName = basename($v1['allpages']['pagename']);
                if (strpos($pageName, ".php") !== false) {
                    $pageName = substr($pageName, 0, -4);
                } else {
                    $pageName = $pageName;
                }
            }
            $selectAllPages[$k1]['allpages']['display_name'] = $pageName;
        }
        $selectAllPages = Common::sort($selectAllPages, '{n}.allpages.display_name', 'asc', array('type' => 'regular', 'ignoreCase' => true));
        $this->set('allPagesDetails', $selectAllPages);
        $mainArray = array();
        $countArrayDataAll = array();

        $mainArrayCounter = 0;
        if (isset($getAllPageVisitDetails) && !empty($getAllPageVisitDetails)) {
            foreach ($getAllPageVisitDetails as $key => $value) {
                $explodePath = explode(",", $value[0]['allpagevisits']);
                $formatedString = "," . $value[0]['allpagevisits'] . ",";
                if (isset($fromPathId, $toPathId) && $fromPathId != 0 && $toPathId != 0) {
                    $firstOccurance = strpos($formatedString, "," . $fromPathId . ",");
                    $lastOccurance = strrpos($formatedString, "," . $toPathId . ",");
                    if ($firstOccurance !== false && $lastOccurance !== false && $firstOccurance < $lastOccurance) {
                        $FinalString = substr(substr($formatedString, 0, -(strlen($formatedString) - $lastOccurance)), $firstOccurance);
                        $FinalString = $FinalString . "," . $toPathId . ",";
                        $AllPageVisited = explode(",", substr($FinalString, 1, -1));
                        $AllPageExplodesString = substr($FinalString, 1, -1);

                        $flag = 1;
                    } else {
                        $AllPageVisited = "";
                    }
                } else if (isset($fromPathId, $toPathId) && $fromPathId == 0 && $toPathId != 0) {
                    $lastOccurance = strrpos($formatedString, "," . $toPathId . ",");
                    if ($lastOccurance !== false) {
                        $FinalString = substr($formatedString, 0, -(strlen($formatedString) - $lastOccurance));
                        $FinalString = $FinalString . ',' . $toPathId;
                        $AllPageVisited = explode(",", $FinalString);
                        $AllPageExplodesString = substr($FinalString, 1, -1);
                        $flag = 1;
                    } else {
                        $AllPageVisited = "";
                    }
                } else if (isset($fromPathId, $toPathId) && $fromPathId != 0 && $toPathId == 0) {
                    $firstOccurance = strpos($formatedString, "," . $fromPathId . ",");
                    if ($firstOccurance !== false) {
                        $FinalString = substr($formatedString, $firstOccurance);
                        $AllPageVisited = explode(",", substr($FinalString, 1, -1));
                        $AllPageExplodesString = substr($FinalString, 1, -1);
                        $flag = 1;
                    } else {
                        $AllPageVisited = "";
                    }
                } else {
                    $AllPageVisited = explode(",", $value[0]['allpagevisits']);
                    $AllPageExplodesString = $value[0]['allpagevisits'];
                }

                if (count($AllPageVisited) > 1) {
                    $countArrayDataAll[implode(",", $AllPageVisited)][] = $AllPageVisited;
                }

                if (count($AllPageVisited) > 1) {
                    $explodeData = explode(",", $AllPageExplodesString);
                    $explodeDate = explode(",", $value[0]['allpagevisitsdates']);
                    $result = array_slice($explodeDate, array_search($AllPageVisited[0], $explodeData), count($AllPageVisited));
                    $dataNeedToBEPResent[implode(",", $AllPageVisited)]['count'] += 1;
                    $cc = implode(",", $AllPageVisited);

                    foreach ($AllPageVisited as $key2 => $value2) {
                        if ($value2 != '') {
                            $selectPages = $this->Allpage->query("SELECT * FROM `allpages` WHERE `id` = " . $value2);
                            $mainArray[$cc][$mainArrayCounter][$key2] = $selectPages[0];
                            $mainArray[$cc][$mainArrayCounter][$key2]['dateValue'] = date('Y-m-d', strtotime($explodeDate[$key2]));
                            $mainArray[$cc][$mainArrayCounter][$key2]['userCode'] = $value['pagevisits']['user_code'];
                        }
                    }
                    $mainArrayCounter++;
                }
            }
            foreach ($mainArray as $kk => $vv) {
                $mainOutPutArray = array();
                foreach ($vv as $kkk => $vvv) {
                    foreach ($vvv as $kkkk => $vvvv) {
                        $test_arr = array();
                        $test_arr = $vvvv['allpages'];
                        $test_arr['user_code'] = $vvvv['userCode'];
                        $mainOutPutArray[$kkk][$vvvv['dateValue']][] = $test_arr;
                    }
                }
                $mainArray[$kk] = $mainOutPutArray;
            }
            $cnt_pers = array();
            if (isset($this->request->data) && !empty($this->request->data) && isset($this->request->data['count_filter']) && $this->request->data['count_filter'] != 'All') {
                if (!empty($mainArray)) {
                    if ($this->request->data['count_filter'] == ">5") {
                        $filteredArray = array_filter($mainArray, function($v, $k) {
                            return count($v) > 5;
                        });
                    } elseif ($this->request->data['count_filter'] == ">7") {
                        $filteredArray = array_filter($mainArray, function($v, $k) {
                            return count($v) > 7;
                        });
                    } elseif ($this->request->data['count_filter'] == ">10") {
                        $filteredArray = array_filter($mainArray, function($v, $k) {
                            return count($v) > 10;
                        });
                    } else {
                         $filteredArray = array_filter($mainArray, function($v, $k) {
                            return count($v) > 12;
                        });
                    }
                    foreach ($filteredArray as $kt => $yt) {
                        $cnt_pers[$kt] = $mainArray[$kt];
                    }
                    $mainArray = $cnt_pers;
                }
            }
            $this->set('AllPathPages', $mainArray);
        }

        $this->set('cont_filter', (isset($this->request->data['count_filter']) && $this->request->data['count_filter'] != 'All') ? $this->request->data['count_filter'] : "All");
        $this->set('selectedFromId', $fromPathId);
        $this->set('selectedToId', $toPathId);
    }

    function pathreport_bak() {
        $this->loadModel('Allpage');
        $this->loadModel('Pagevisit');
        $this->loadModel('Leadinfo');

        $fromPathId = $_POST['fromSitePath'];
        $toPathId = $_POST['toSitePath'];

        if (count($_POST) > 0 && isset($_POST['startDate'], $_POST['endDate']) && $_POST['startDate'] != '' && $_POST['endDate']) {
            $getAllPageVisitDetails = $this->Pagevisit->query("SELECT COUNT(id) as totalHitPath, `allpagesvisit`,`user_code`,created_at FROM `pagevisits` WHERE DATE(`created_at`)>='" . $_POST['startDate'] . "' AND DATE(`created_at`)<='" . $_POST['endDate'] . "' AND `sourcesite`='" . $_POST['souceSites'] . "' GROUP BY `allpagesvisit` ORDER BY id DESC");
            $selectAllPages = $this->Allpage->query("SELECT * FROM allpages WHERE sourcetitle='" . $_POST['souceSites'] . "'");
            $this->set('allPagesDetails', $selectAllPages);
        } else {
            $getAllPageVisitDetails = $this->Pagevisit->query("SELECT COUNT(id) as totalHitPath, `allpagesvisit`,`user_code` FROM `pagevisits` WHERE DATE(`created_at`)>=curdate() AND DATE(`created_at`)<=curdate() AND `sourcesite`='www.orangescrum.org' GROUP BY `allpagesvisit` ORDER BY id DESC");

            $selectAllPages = $this->Allpage->query("SELECT * FROM allpages WHERE sourcetitle='www.orangescrum.org'");
            $this->set('allPagesDetails', $selectAllPages);
        }

        $mainArray = array();

        //echo "<pre>";//print_r($getAllPageVisitDetails);

        if (count($getAllPageVisitDetails) > 0) {
            foreach ($getAllPageVisitDetails as $key => $value) {
                $countTotalUser = $value[0]['totalHitPath'];
                $formatedString = "," . $value['pagevisits']['allpagesvisit'] . ",";
                if (isset($fromPathId, $toPathId) && $fromPathId != 0 && $toPathId != 0) {
                    $firstOccurance = strpos($formatedString, "," . $fromPathId . ",");
                    $lastOccurance = strrpos($formatedString, "," . $toPathId . ",");

                    if ($firstOccurance !== false && $lastOccurance !== false && $firstOccurance < $lastOccurance) {
                        $FinalString = substr($formatedString, $firstOccurance, $lastOccurance);
                        $FinalString = $FinalString . "," . $toPathId . ",";
                        $AllPageVisited = explode(",", substr($FinalString, 1, -1));
                    } else {
                        $AllPageVisited = "";
                    }
                } else {
                    $AllPageVisited = explode(",", $value['pagevisits']['allpagesvisit']);
                }

                $DateCounter = 0;
                foreach ($AllPageVisited as $key1 => $value1) {
                    $allLeadInfos = $this->Leadinfo->query("SELECT * FROM leadinfos WHERE `USER_CODE`='" . $value['pagevisits']['user_code'] . "'");
                    $allUrlsVisit = $allLeadInfos[0]['leadinfos']['USER_URLS'];
                    $allVisitArray = json_decode($allUrlsVisit, true);
                    if ($value1 != '') {
                        $selectPages = $this->Allpage->query("SELECT * FROM `allpages` WHERE `id` = " . $value1);
                        $mainArray[$key][0] = $countTotalUser;
                        $mainArray[$key][1][$key1] = $selectPages[0];
                        $mainArray[$key][1][$key1]['dateValue'] = date("Y-m-d", strtotime($allVisitArray['urls'][$DateCounter]['lastmodified']));
                    }
                    $DateCounter++;
                }
            }

            foreach ($mainArray as $kk => $vv) {
                $mainOutPutArray = array();
                foreach ($vv[1] as $kkk => $vvv) {
                    $mainOutPutArray[$vvv['dateValue']][] = $vvv['allpages'];
                }
                $mainArray[$kk][2] = $mainOutPutArray;
            }

            $this->set('AllPathPages', $mainArray);
        }
        //exit;
        $this->set('fromdateset', $_POST['startDate']);
        $this->set('todateset', $_POST['endDate']);
        $this->set('sourcevalset', $_POST['souceSites']);

        $this->set('selectedFromId', $fromPathId);
        $this->set('selectedToId', $toPathId);
    }

    function getAjaxPages() {
        $this->layout = "ajax";
        $this->loadModel('Allpage');

        $selectAllPages = $this->Allpage->query("SELECT * FROM allpages WHERE sourcetitle='" . $this->request->data['pathVal'] . "'");

        foreach ($selectAllPages as $k => $v) {
            if (basename($v['allpages']['pagename']) == 'www.orangescrum.com' || basename($v['allpages']['pagename']) == 'www.andolasoft.com' || basename($v['allpages']['pagename']) == 'www.orangescrum.org' || basename($v['allpages']['pagename']) == 'wakeupsales.org') {
                $selectAllPages[$k]['allpages']['pagename'] = 'Home';
            } else {
                $pageName = basename($v['allpages']['pagename']);

                if (strpos($pageName, ".php") !== false) {
                    $pageName = substr($pageName, 0, -4);
                } else {
                    $pageName = $pageName;
                }

                $selectAllPages[$k]['allpages']['pagename'] = $pageName;
            }
        }
        echo json_encode($selectAllPages);
        exit;
    }
	
	function getkeyactivity(){
		$this->layout = 'ajax';
		$this->loadModel('Trackevent');
		$getMonthyear = explode("-", $this->request->data['monthyear']);
		$getWeek = $this->request->data['weekval'];
		$getMonth = $getMonthyear[0];
		$getYear = $getMonthyear[1];

		if($getWeek == 1){
			$dayStart = 1;
			$dayEnd = 7;
		}elseif($getWeek == 2){
			$dayStart = 8;
			$dayEnd = 14;
		}elseif($getWeek == 3){
			$dayStart = 15;
			$dayEnd = 21;
		}else if($getWeek == 4){
			$dayStart = 22;
			$dayEnd = 28;
		}else{
			$dayStart = 29;
			$dayEnd = 31;
		}
		
		$getAllRespectiveActivity = $this->Trackevent->query("SELECT `event_refer`, `event_name`, count(*) as TotalActivity FROM `trackevents` WHERE `event_refer` != '' AND `event_name` != '' AND ADDTIME(`created_at`, '05:30:30') >= '".$getYear."-".$getMonth."-".$dayStart." 00:00:00' AND ADDTIME(`created_at`, '05:30:30') <= '".$getYear."-".$getMonth."-".$dayEnd." 23:59:59' GROUP BY `event_name` order by `event_name` ASC");
		
		//echo "<pre>";print_r($getAllRespectiveActivity);exit;
		
		echo json_encode($getAllRespectiveActivity);
		exit;
		
	}
	
    function cohortreport() {
        if ($this->request->is('ajax')) {
            $this->loadModel('Allpage');
            $this->loadModel('Pagevisit');
            $this->loadModel('Lead');
            $this->loadModel('OsPlan');
            $this->loadModel('Prospect');
			$this->loadModel('Addonpayment');
			$this->loadModel('Trackevent');
			$this->loadModel('SubscriptionTrack');
            $data = $this->request->data;
            $monthArray = array('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December');
            $startMonthArray = split("-", $data['startDate']);
            $startYear = $startMonthArray[1];
            $endMonthArray = split("-", $data['endDate']);
            $endYear = $endMonthArray[1];
            $sourceSite = trim($data['sourceSites']);
            $countArray = 0;
            $getMonthwiseVisCount = array();
            $weekcountlead = 0;
			$weekcountpurchase = 0;
			$weekcountactivity = 0;
			$weekcountpaidusers = 0;
			
            for ($i = $startYear; $i <= $endYear; $i++) {
                $startMonth = ($i == $startYear) ? $startMonthArray[0] : 1;
                $endMonth = ($i == $endYear) ? $endMonthArray[0] : 12;
                for ($j = $startMonth; $j <= $endMonth; $j++) {
                    $getMonthwiseData[$countArray]['monthYear'] = $getMonthwiseVisCount[$countArray]['monthYear'] = $monthArray[$j - 1] . ", " . $i;
                    $getAllVisCount = $this->Prospect->query("SELECT count(*) as `TotalProspects` FROM `prospects` WHERE `prospects`.`IP` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') AND `SOURCE_NAME`='" . $sourceSite . "' AND MONTH(ADDTIME(`CREATED`, '05:30:00')) = " . $j . " AND YEAR(ADDTIME(`CREATED`, '05:30:00')) = " . $i);
                    $getMonthwiseVisCount[$countArray]['TotalVisitors'] = $getAllVisCount[0][0]['TotalProspects'];
                    $weekstart = 1;
                    $weekend = 7;
                    for ($weekcnt = 1; $weekcnt <= 5; $weekcnt++) {
                        if ($weekstart == 29) {
                            
							$getWeekVisCount = $this->Prospect->query("SELECT count(*) as `TotalProspectsWeek` FROM `prospects` WHERE `prospects`.`IP` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') AND `SOURCE_NAME`='" . $sourceSite . "' AND ADDTIME(`CREATED`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND CONCAT(LAST_DAY('" . $i . "-" . $j . "-" . $weekstart . "'),' ', '23:59:59')");
							
							$getAllPeople = $this->Lead->query("SELECT count(*) as `TotalLeads` FROM `leads` WHERE `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') AND `SOURCE_NAME`='" . $sourceSite . "' AND ADDTIME(`CREATED`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND CONCAT(LAST_DAY('" . $i . "-" . $j . "-" . $weekstart . "'),' ', '23:59:59')");
							
							if($sourceSite == 'www.orangescrum.org'){
								$getAllPurchase = $this->Addonpayment->query("SELECT count(*) as `TotalPurchase` FROM `addonpayments` WHERE ADDTIME(`payment_on`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND CONCAT(LAST_DAY('" . $i . "-" . $j . "-" . $weekstart . "'),' ', '23:59:59')");
							}	
							
							if($sourceSite == 'www.orangescrum.com' || $sourceSite == 'app.orangescrum.com'){
								$getAllKeyActivity = $this->Trackevent->query("SELECT count(*) as `TotalKeyActivity` FROM `trackevents` WHERE `event_refer` != '' AND `event_name` != '' AND ADDTIME(`created_at`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND CONCAT(LAST_DAY('" . $i . "-" . $j . "-" . $weekstart . "'),' ', '23:59:59')");
								$getAllPaidUsers = $this->SubscriptionTrack->query("SELECT count(*) as `TotalPaidUsers` FROM `subscription_tracks` WHERE `plan_status`='Active' AND user_type='Owner' AND `current_active`=1 AND ADDTIME(`created`, '05:30:30') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND CONCAT(LAST_DAY('" . $i . "-" . $j . "-" . $weekstart . "'),' ', '23:59:59') AND `plan_id` IN(5,10,12,14)");
							}
							
                        } else {
                            $getWeekVisCount = $this->Prospect->query("SELECT count(*) as `TotalProspectsWeek` FROM `prospects` WHERE `prospects`.`IP` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') AND `SOURCE_NAME`='" . $sourceSite . "' AND ADDTIME(`CREATED`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND '" . $i . "-" . $j . "-" . $weekend . " 23:59:59'");
							
							$getAllPeople = $this->Lead->query("SELECT count(*) as `TotalLeads` FROM `leads` WHERE `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') AND `SOURCE_NAME`='" . $sourceSite . "' AND ADDTIME(`CREATED`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND '" . $i . "-" . $j . "-" . $weekend . " 23:59:59'");
							
							if($sourceSite == 'www.orangescrum.org'){
								$getAllPurchase = $this->Addonpayment->query("SELECT count(*) as `TotalPurchase` FROM `addonpayments` WHERE ADDTIME(`payment_on`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND '" . $i . "-" . $j . "-" . $weekend . " 23:59:59'");
							}	
							
							if($sourceSite == 'www.orangescrum.com' || $sourceSite == 'app.orangescrum.com'){
								$getAllKeyActivity = $this->Trackevent->query("SELECT count(*) as `TotalKeyActivity` FROM `trackevents` WHERE `event_refer` != '' AND `event_name` != '' AND ADDTIME(`created_at`, '05:30:00') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND '" . $i . "-" . $j . "-" . $weekend . " 23:59:59'");
								
								$getAllPaidUsers = $this->SubscriptionTrack->query("SELECT count(*) as `TotalPaidUsers` FROM `subscription_tracks` WHERE `plan_status`='Active' AND user_type='Owner' AND `current_active`=1 AND ADDTIME(`created`, '05:30:30') BETWEEN '" . $i . "-" . $j . "-" . $weekstart . " 00:00:00' AND '" . $i . "-" . $j . "-" . $weekend . " 23:59:59' AND `plan_id` IN(5,10,12,14)");
							}	
							
                        }						
						
                        $weekstart = $weekstart + 7;
                        $weekend = $weekend + 7;
						$getMonthwiseData[$countArray]['week' . $weekcnt . '_visitor'] = $getWeekVisCount[0][0]['TotalProspectsWeek'];
                        $getMonthwiseData[$countArray]['week' . $weekcnt] = $getAllPeople[0][0]['TotalLeads'];
						$getMonthwiseData[$countArray]['week' . $weekcnt . '_purchase'] = $getAllPurchase[0][0]['TotalPurchase'];
						$getMonthwiseData[$countArray]['week' . $weekcnt . '_paidusers'] = $getAllPaidUsers[0][0]['TotalPaidUsers'];
						$getMonthwiseData[$countArray]['week' . $weekcnt . '_keyactivity'] = $getAllKeyActivity[0][0]['TotalKeyActivity'];
                        $weekcountlead = $weekcountlead + (int) $getAllPeople[0][0]['TotalLeads'];
						$weekcountpurchase = $weekcountpurchase + (int) $getAllPurchase[0][0]['TotalPurchase'];
						$weekcountpaidusers = $weekcountpaidusers + (int) $getAllPaidUsers[0][0]['TotalPaidUsers'];
						$weekcountactivity = $weekcountactivity + (int) $getAllKeyActivity[0][0]['TotalKeyActivity'];
                    }
                    $getMonthwiseData[$countArray]['TotalLeads'] = $weekcountlead;
					$getMonthwiseData[$countArray]['TotalPurchase'] = $weekcountpurchase;
					$getMonthwiseData[$countArray]['TotalPaidUsers'] = $weekcountpaidusers;
					$getMonthwiseData[$countArray]['TotalKeyActivity'] = $weekcountactivity;
                    $weekcountlead = $weekcountpurchase = $weekcountactivity = $weekcountpaidusers = 0;
                    $countArray++;
                }
            }
            //To get subscription data
            if (trim($sourceSite) == "www.orangescrum.com") {
                //Get Free Plan Ids
                $this->loadModel('OsPlan');
                $options_free_pl_arr = array('fields' => array('OsPlan.plan_id', 'OsPlan.plan_name'), 'conditions' => array('OR' => array(
                            array('OsPlan.plan_name' => 'Free'),
                            array('OsPlan.plan_name' => 'Free Trial Expired')
                )));
                $free_pla_ids = array_keys($this->OsPlan->find('list', $options_free_pl_arr));
                $this->loadModel('SubscriptionTrack');
                $countArray = 0;
                for ($i = $startYear; $i <= $endYear; $i++) {
                    $startMonth = ($i == $startYear) ? $startMonthArray[0] : 1;
                    $endMonth = ($i == $endYear) ? $endMonthArray[0] : 12;
                    for ($j = $startMonth; $j <= $endMonth; $j++) {
                        $start_date_arr = $this->Format->get_page_month($j . "-" . $i);
                        $getMonthwiseUsers[$countArray]['monthYear'] = $monthArray[$j - 1] . ", " . $i;
                        $getMonthwiseUsers[$countArray]['total_free'] = $this->SubscriptionTrack->find('count', array('conditions' => array('and' => array(array('DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) <=' => $start_date_arr['end_date'], 'DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) >=' => $start_date_arr['start_date']), 'SubscriptionTrack.plan_status' => "Active", 'SubscriptionTrack.user_type' => "Owner", 'SubscriptionTrack.current_active' => "1", 'SubscriptionTrack.plan_id' => $free_pla_ids, "NOT" => array("SubscriptionTrack.plan_id" => 13)))));
                        $getMonthwiseUsers[$countArray]['total_paid'] = $this->SubscriptionTrack->find('count', array('conditions' => array('and' => array(array('DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) <=' => $start_date_arr['end_date'], 'DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) >=' => $start_date_arr['start_date']), 'SubscriptionTrack.plan_status' => "Active", 'SubscriptionTrack.user_type' => "Owner", 'SubscriptionTrack.current_active' => "1", "NOT" => array("SubscriptionTrack.plan_id" => $free_pla_ids)))));
                        $getMonthwiseUsers[$countArray]['total_downgraded'] = $this->SubscriptionTrack->find('count', array('conditions' => array('and' => array(array('DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) <=' => $start_date_arr['end_date'], 'DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) >=' => $start_date_arr['start_date']), 'SubscriptionTrack.plan_status' => "Active", 'SubscriptionTrack.user_type' => "Owner", 'SubscriptionTrack.current_active' => "1", 'SubscriptionTrack.downgrade_status' => '1'))));
                        $getMonthwiseUsers[$countArray]['total_upgraded'] = $this->SubscriptionTrack->find('count', array('conditions' => array('and' => array(array('DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) <=' => $start_date_arr['end_date'], 'DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) >=' => $start_date_arr['start_date']), 'SubscriptionTrack.plan_status' => "Active", 'SubscriptionTrack.user_type' => "Owner", 'SubscriptionTrack.current_active' => "1", 'SubscriptionTrack.upgrade_status' => '1'))));
                        $getMonthwiseUsers[$countArray]['expired'] = $this->SubscriptionTrack->find('count', array('conditions' => array('and' => array(array('DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) <=' => $start_date_arr['end_date'], 'DATE(ADDTIME(`SubscriptionTrack.created`, "05:30:00")) >=' => $start_date_arr['start_date']), 'SubscriptionTrack.plan_status' => "Active", 'SubscriptionTrack.user_type' => "Owner", 'SubscriptionTrack.current_active' => "1", 'SubscriptionTrack.plan_id' => '13'))));
//                        $log = $this->SubscriptionTrack->getDataSource()->getLog(false, false);
//                        debug($log);
                        $countArray++;
                    }
                }
                $sub_array = $getMonthwiseUsers;
            } else {
                $sub_array = array();
            }
            $plans = array('fields' => array('OsPlan.plan_id', 'OsPlan.plan_name'), 'conditions' => array('OsPlan.plan_sequence >' => 0, 'NOT' => array('OsPlan.plan_name' => 'Free')), 'order' => array('OsPlan.plan_name' => 'ASC'));
            $pplans = $this->OsPlan->find('list', $plans);
            $resp_array = array('getMonthwiseData' => $getMonthwiseData, 'sub_array' => $sub_array, 'os_plans' => $pplans, 'sourceSite' => $sourceSite, 'getMonthwiseVisData' => $getMonthwiseVisCount);
            echo json_encode($resp_array);
            exit;
        }
        $this->set('FromDateDisplay', date('01-Y'));
        $this->set('ToDateDisplay', date('m-Y'));
        $this->loadModel('OsPlan');
        $plans = array('fields' => array('OsPlan.plan_id', 'OsPlan.plan_name'), 'conditions' => array('OsPlan.plan_sequence >' => 0, 'NOT' => array('OsPlan.plan_name' => 'Free')), 'order' => array('OsPlan.plan_name' => 'ASC'));
        $pplans = $this->OsPlan->find('list', $plans);
        $this->set('pplans', $pplans);
    }

    function funnelreport() {
        $this->loadModel('Lead');
        $this->loadModel('Leadinfo');
        $this->loadModel('Prospect');
        $this->loadModel('Prospectvisit');
        $this->loadModel('Addonpayment');
        //$this->request->data['souceSites'] = "www.orangescrum.com";
        if (count($_POST) > 0 && isset($_POST['startDate'], $_POST['endDate']) && $_POST['startDate'] != '' && $_POST['endDate']) {
            $start = $_POST['startDate'] . " 00:00:00";
            $end = $_POST['endDate'] . " 23:59:59";
            $getAllProspectsCount = $this->Prospect->query("SELECT COUNT(*) as totalProspects FROM `prospects` WHERE ADDTIME(`CREATED`, '05:30:00') >= '" . $start . "' AND ADDTIME(`CREATED`, '05:30:00')<='" . $end . "' AND `SOURCE_NAME`='" . $_POST['souceSites'] . "' AND `IP` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
            $sColumns = '`leads`.`ID`, `leads`.`USER_CODE`';
            $sWhere = "WHERE ADDTIME(`leads`.`CREATED`, '05:30:00') >= '" . $start . "' AND ADDTIME(`leads`.`CREATED`, '05:30:00')<='" . $end . "' AND IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')";
            if ($this->request->data['souceSites'] == "www.orangescrum.com") {
				$join = "INNER JOIN subscription_tracks ON subscription_tracks.email=leads.EMAIL AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') >= '" . $start . "' AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') <= '" . $end . "' AND subscription_tracks.`plan_status`='Active' AND subscription_tracks.`user_type`='Owner' AND subscription_tracks.`current_active`=1";
				$sWhere .= ($sWhere == "") ? "WHERE (`leads`.`SOURCE_NAME`='www.orangescrum.com' OR `leads`.`SOURCE_NAME`='app.orangescrum.com')" : " AND (`leads`.`SOURCE_NAME`='www.orangescrum.com' OR `leads`.`SOURCE_NAME`='app.orangescrum.com')";
            } else {
				$join = "";
                $sWhere .= ($sWhere == "") ? "WHERE (`leads`.`SOURCE_NAME`='" . $_POST['souceSites'] . "')" : " AND (`leads`.`SOURCE_NAME`='" . $_POST['souceSites'] . "')";
            }
            
			$leads_query = "SELECT {$sColumns} FROM `leads` {$join} {$sWhere}";
            $getAllLeadsCount = $this->Lead->query($leads_query);
            $getAddonpayment = $this->Addonpayment->query("SELECT * FROM `addonpayments` WHERE ADDTIME(`payment_on`, '05:30:00') BETWEEN '" . $start . "' AND '" . $end . "' ORDER BY payment_on DESC");
            if ($this->request->data['souceSites'] == "www.orangescrum.com") {
                $this->loadModel('OsPlan');
                $this->loadModel('SubscriptionTrack');
                $options_free_pl_arr = array('fields' => array('OsPlan.plan_id', 'OsPlan.plan_name'), 'conditions' => array('OsPlan.plan_name' => 'Free'));
                $free_pla_ids = array_keys($this->OsPlan->find('list', $options_free_pl_arr));
				
				
                $free_pla_count = $this->SubscriptionTrack->query("SELECT leads.*, subscription_tracks.* FROM leads, subscription_tracks WHERE `leads`.`EMAIL`=`subscription_tracks`.`email` AND (`leads`.`SOURCE_NAME`='www.orangescrum.com' OR `leads`.`SOURCE_NAME`='app.orangescrum.com') AND `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') AND ADDTIME(`leads`.`CREATED`, '05:30:00') >= '" . $start . "' AND ADDTIME(`leads`.`CREATED`, '05:30:00') <= '" . $end . "' AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') >= '" . $start . "' AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') <= '" . $end . "' AND subscription_tracks.`plan_status`='Active' AND subscription_tracks.`user_type`='Owner' AND subscription_tracks.`current_active`=1 AND `subscription_tracks`.`plan_id` IN (" . implode(",", $free_pla_ids) . ")");
                $total_paid = $this->SubscriptionTrack->query("SELECT leads.*, subscription_tracks.* FROM leads, subscription_tracks WHERE `leads`.`EMAIL`=`subscription_tracks`.`email` AND (`leads`.`SOURCE_NAME`='www.orangescrum.com' OR `leads`.`SOURCE_NAME`='app.orangescrum.com') AND ADDTIME(`leads`.`CREATED`, '05:30:00') >= '" . $start . "' AND ADDTIME(`leads`.`CREATED`, '05:30:00') <= '" . $end . "' AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') >= '" . $start . "' AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') <= '" . $end . "' AND subscription_tracks.`plan_status`='Active' AND subscription_tracks.`user_type`='Owner' AND subscription_tracks.`current_active`=1 AND `subscription_tracks`.`plan_id` NOT IN (" . implode(",", $free_pla_ids) . ")");
                $this->set('os_total_free', count($free_pla_count));
                $this->set('os_total_paid', count($total_paid));
                $total_signup_prospects = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com/signup%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='" . $this->request->data['souceSites'] . "' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
             //   echo "<pre>";print_r($total_signup_prospects);
             //   echo "SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE 'http%://www.orangescrum.com/tutorials%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='" . $this->request->data['souceSites'] . "' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')";exit;
			 
			 
                $this->set('totalSignupProspects', $total_signup_prospects[0][0]['count']);
                $get_sign_up_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND (l.PAGE_NAME='signup') AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
				
				
				
                $this->set('totalSignupleads', $get_sign_up_leads[0][0]['counts']);
				
				
				
			    $total_signup_prospects_b = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com/signup?alter=a%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='" . $this->request->data['souceSites'] . "' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
             	
			
				

			//   echo "<pre>";print_r($total_signup_prospects);
             //   echo "SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE 'http%://www.orangescrum.com/tutorials%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='" . $this->request->data['souceSites'] . "' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')";exit;
			 
			 
                $this->set('totalSignupProspects_b', $total_signup_prospects_b[0][0]['count']);
                $get_sign_up_leads_b = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND (l.PAGE_NAME = 'signup_abtest') AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
			
                $this->set('totalSignupleads_b', $get_sign_up_leads_b[0][0]['counts']);
            }
        } else {
            $getAllProspectsCount = $this->Prospect->query("SELECT COUNT(*) as `totalProspects` FROM `prospects` WHERE `SOURCE_NAME`='www.orangescrum.org' AND DATE(ADDTIME(`CREATED`, '05:30:00'))>=curdate() AND DATE(ADDTIME(`CREATED`, '05:30:00'))<=curdate() AND `IP` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
            $getAllLeadsCount = $this->Lead->query("SELECT `ID`, `USER_CODE` FROM `leads` WHERE `SOURCE_NAME`='www.orangescrum.org' AND DATE(ADDTIME(`CREATED`, '05:30:00'))>=curdate() AND DATE(ADDTIME(`CREATED`, '05:30:00'))<=curdate() AND IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
            $getAddonpayment = $this->Addonpayment->query("SELECT * FROM `addonpayments` WHERE date(ADDTIME(`payment_on`, '05:30:00'))=curdate() ORDER BY payment_on DESC");
        }
		

        $totalAddonBuyersCount = count($getAddonpayment);
        $this->set('totalAddonBuyersCount', $totalAddonBuyersCount);
        $this->set('getAddonpayment', $getAddonpayment);


        $addonCount = $addonPaymentCount = 0;
        $CountService = $CountServicePlanOne = $CountServicePlanTwo = $CountServicePlanThree = 0;

        foreach ($getAllLeadsCount as $key => $value) {
            $getAllLeadsInfo = $this->Leadinfo->query("SELECT `USER_URLS` FROM `leadinfos` WHERE `USER_CODE`='" . $value['leads']['USER_CODE'] . "'");
            $visitedUrls = $getAllLeadsInfo[0]['leadinfos']['USER_URLS'];
            if (strpos($visitedUrls, "http://www.orangescrum.org/add-on") !== FALSE) {
                $addonCount++;
            }
            if (strpos($visitedUrls, "Click on the timelog addon page") !== FALSE) {
                $addonPaymentCount++;
            }
            if (strpos($visitedUrls, "http://www.orangescrum.org/services") !== FALSE) {
                $CountService++;
            }
            if (strpos($visitedUrls, "Installation Support: $499 - one time") !== FALSE) {
                $CountServicePlanOne++;
            }
            if (strpos($visitedUrls, "Standard Support in addition to Time Log & Invoice Add-on: $799 - one time") !== FALSE) {
                $CountServicePlanTwo++;
            }
            if (strpos($visitedUrls, "Advanced Support in addition to Time Log, Invoice, Task Status Group & API Add-on: $1799 - per year") !== FALSE) {
                $CountServicePlanThree++;
            }
        }
        if ($getAllProspectsCount[0][0]['totalProspects']) {
            $totalPercentageLeads = (count($getAllLeadsCount) / $getAllProspectsCount[0][0]['totalProspects']) * 100;
            $this->set('totalPercentageLeads', $totalPercentageLeads);
        }

        $this->set('TotalProspectsCount', $getAllProspectsCount[0][0]['totalProspects']);
        $this->set('TotalLeadsCount', count($getAllLeadsCount));


        $this->set('totalforAddonPage', $addonCount);
        if ($getAllLeadsCount) {
            $totalPercentageAddon = ($addonCount / count($getAllLeadsCount)) * 100;
            $this->set('totalPercentageAddon', $totalPercentageAddon);
        }
        $this->set('totalforAddonPaymentPage', $addonPaymentCount);
        if ($addonCount) {
            $totalPercentageAddonPayment = ($addonPaymentCount / $addonCount) * 100;
            $this->set('totalPercentageAddonPayment', $totalPercentageAddonPayment);
        }
        $highChartValue = "[['Visited Website(100%)', " . $getAllProspectsCount[0][0]['totalProspects'] . "]";
        if (count($getAllLeadsCount) > 0) {
            $highChartValue .= ",['Became Leads(" . number_format($totalPercentageLeads, 2) . "%)', " . count($getAllLeadsCount) . "]";
        }
        if ($addonCount > 0) {
            $highChartValue .= ",['Leads in Addon Page(" . number_format($totalPercentageAddon, 2) . "%)', " . $addonCount . "]";
        }
        if ($addonPaymentCount > 0) {
            $highChartValue .= ",['Leads in Addon Payment Page(" . number_format($totalPercentageAddonPayment, 2) . "%)', " . $addonPaymentCount . "]";
        }
        $highChartValue .= "]";

        $this->set('highChartValues', $highChartValue);


        $this->set('totalforServiceCount', $CountService);
        if ($getAllLeadsCount) {
            $totalPercentageService = ($CountService / count($getAllLeadsCount)) * 100;
            $this->set('totalPercentageService', $totalPercentageService);
        }
        $this->set('totalforServicePlanOne', $CountServicePlanOne);
        if ($CountService) {
            $totalPercentagePlanOne = ($CountServicePlanOne / $CountService) * 100;
            $this->set('totalPercentagePlanOne', $totalPercentagePlanOne);
        }
        $this->set('totalforServicePlanTwo', $CountServicePlanTwo);
        if ($CountService) {
            $totalPercentagePlanTwo = ($CountServicePlanTwo / $CountService) * 100;
            $this->set('totalPercentagePlanTwo', $totalPercentagePlanTwo);
        }
        $this->set('totalforServicePlanThree', $CountServicePlanThree);
        if ($CountService) {
            $totalPercentagePlanThree = ($CountServicePlanThree / $CountService) * 100;
            $this->set('totalPercentagePlanThree', $totalPercentagePlanThree);
        }
        $highChartValueServices1 = "[['Visited Website(100%)', " . $getAllProspectsCount[0][0]['totalProspects'] . "]";
        if (count($getAllLeadsCount) > 0) {
            $highChartValueServices1 .= ",['Downloaded(" . number_format($totalPercentageLeads, 2) . "%)', " . count($getAllLeadsCount) . "]";
        }
        if ($CountService > 0) {
            $highChartValueServices1 .= ",['Leads in Service Page(" . number_format($totalPercentageService, 2) . "%)', " . $CountService . "]";
        }
        if ($CountServicePlanOne > 0) {
            $highChartValueServices1 .= ",['Purchased Service(" . number_format($totalPercentagePlanOne, 2) . "%)', " . $CountServicePlanOne . "]";
        }
        $highChartValueServices1 .= "]";


        $highChartValueServices2 = "[['Visited Website(100%)', " . $getAllProspectsCount[0][0]['totalProspects'] . "]";
        if (count($getAllLeadsCount) > 0) {
            $highChartValueServices2 .= ",['Downloaded(" . number_format($totalPercentageLeads, 2) . "%)', " . count($getAllLeadsCount) . "]";
        }
        if ($CountService > 0) {
            $highChartValueServices2 .= ",['Leads in Service Page(" . number_format($totalPercentageService, 2) . "%)', " . $CountService . "]";
        }
        if ($CountServicePlanTwo > 0) {
            $highChartValueServices2 .= ",['Purchased Service(" . number_format($totalPercentagePlanTwo, 2) . "%)', " . $CountServicePlanTwo . "]";
        }
        $highChartValueServices2 .= "]";


        $highChartValueServices3 = "[['Visited Website(100%)', " . $getAllProspectsCount[0][0]['totalProspects'] . "]";
        if (count($getAllLeadsCount) > 0) {
            $highChartValueServices3 .= ",['Downloaded(" . number_format($totalPercentageLeads, 2) . "%)', " . count($getAllLeadsCount) . "]";
        }
        if ($CountService > 0) {
            $highChartValueServices3 .= ",['Leads in Service Page(" . number_format($totalPercentageService, 2) . "%)', " . $CountService . "]";
        }
        if ($CountServicePlanThree > 0) {
            $highChartValueServices3 .= ",['Purchased Service(" . number_format($totalPercentagePlanThree, 2) . "%)', " . $CountServicePlanThree . "]";
        }
        $highChartValueServices3 .= "]";

        $this->set('highChartValueServices1', $highChartValueServices1);
        $this->set('highChartValueServices2', $highChartValueServices2);
        $this->set('highChartValueServices3', $highChartValueServices3);
        if (isset($_POST['startDate'])) {
            $this->set('fromdateset', $_POST['startDate']);
        }
        if (isset($_POST['endDate'])) {
            $this->set('todateset', $_POST['endDate']);
        }
        if (isset($_POST['souceSites']) && $_POST['souceSites'] != '') {
            $this->set('sourcevalset', $_POST['souceSites']);
        } else {
            $this->set('sourcevalset', 'www.orangescrum.org');
        }
		
		
        $this->loadModel('OsPlan');
        $allPlans = $this->OsPlan->find('list', array(
            'fields' => array('OsPlan.plan_id', 'OsPlan.plan_name'),
            'recursive' => -1
        ));
        /* $allPlans = array_filter($allPlans, function($v, $k) {
            return strpos($v, 'Free') === false;
        }); */
		
		  $allPlans=array();
        $this->set('paid_plans', $allPlans);
    }

    public function addonpayment() {
        $this->layout = "ajax";
        $this->loadModel('Addonpayment');

        $AddonType = $this->request->data['addon_type'];
        $PaymentOn = $this->request->data['payment_on'];
        $Buyername = $this->request->data['buyername'];
        $BuyerEmail = $this->request->data['buyeremail'];

        if (isset($AddonType, $Buyername, $BuyerEmail)) {
            //$InsertAddonPaymentSuccess = $this->Addonpayment->query("INSERT INTO `addonpayments` SET `buyer_name`='".$Buyername."', `buyer_email`='".$BuyerEmail."', `payment_on`='".$PaymentOn."', `addon_type`='".$AddonType."'");
            $InsertAddonPaymentSuccess = $this->Addonpayment->query("INSERT INTO `addonpayments` SET `buyer_name`='" . $Buyername . "', `buyer_email`='" . $BuyerEmail . "', `payment_on`=now(), `addon_type`='" . $AddonType . "'");
        }

        exit;
    }

    public function saveeventtrack() {
        $is_private = $this->Format->checkprivateip();
        if(!$is_private){
            $this->layout = "ajax";
            $this->loadModel('Trackevent');
            $eventName = $this->request->data['event_name'];
            $eventRefer = $this->request->data['eventRefer'];
            $email_id = $this->request->data['email_id'];
            //echo "INSERT INTO `trackevents` SET `email`='".$email_id."', `event_name`='".$eventName."', `event_refer`='".$eventRefer."', `created_at`=now()";
    		//echo "INSERT INTO `trackevents` SET `email`='" . $email_id . "', `event_name`='" . $eventName . "', `event_refer`='" . $eventRefer . "', `created_at`=now()";
            $insertTrackEvents = $this->Trackevent->query("INSERT INTO `trackevents` SET `email`='" . $email_id . "', `event_name`='" . $eventName . "', `event_refer`='" . $eventRefer . "', `created_at`=now()");
        }
        exit;
    }

    public function analytic() {
        $this->loadModel('Trackevent');
        $this->loadModel('SubscriptionTrack');
        $eventnames = '';

        $prev_arr = Common::getDayDiff(date('Y-m-d'), date('Y-m-d'));
        if (count($_POST) > 0 && isset($_POST['startDate'], $_POST['endDate']) && $_POST['startDate'] != '' && $_POST['endDate']) {
            if (isset($this->request->data['actionSite']) && !empty($this->request->data['actionSite'])) {
                foreach ($this->request->data['actionSite'] as $action) {
                    $eventnames .= "'" . $action . "',";
                }
                $eventnames = rtrim($eventnames, ',');
            }
			
            if ($eventnames != "") {
                $SelectTrackEvents = $this->Trackevent->query("SELECT `email` , `event_name` , `event_refer` , COUNT( `event_refer` ) AS TotalCount FROM `trackevents` WHERE DATE(`created_at`) BETWEEN '" . $_POST['startDate'] . " 00:00:00' AND '" . $_POST['endDate'] . " 23:59:59' AND `event_name` IN (" . $eventnames . ") GROUP BY `event_name` , `event_refer` ASC");
                $prev_arr = Common::getDayDiff($_POST['startDate'], $_POST['endDate']);
                $prev_SelectTrackEvents = $this->Trackevent->query("SELECT `email` , `event_name` , `event_refer` , COUNT( `event_refer` ) AS TotalCount FROM `trackevents` WHERE DATE(`created_at`) BETWEEN '" . $prev_arr['prev_start'] . " 00:00:00' AND '" . $prev_arr['prev_end'] . " 23:59:59' AND `event_name` IN (" . $eventnames . ") GROUP BY `event_name` , `event_refer` ASC");
            } else {
                $SelectTrackEvents = $this->Trackevent->query("SELECT `email` , `event_name` , `event_refer` , COUNT( `event_refer` ) AS TotalCount FROM `trackevents` WHERE DATE(`created_at`)>=curdate() AND DATE(`created_at`)<=curdate() GROUP BY `event_name`, `event_refer` ASC");
                $prev_SelectTrackEvents = $this->Trackevent->query("SELECT `email` , `event_name` , `event_refer` , COUNT( `event_refer` ) AS TotalCount FROM `trackevents` WHERE DATE(`created_at`)>= '" . $prev_arr['prev_start'] . "' AND DATE(`created_at`)<= '" . $prev_arr['prev_end'] . "' GROUP BY `event_name`, `event_refer` ASC");
            }
        } else {
            $SelectTrackEvents = $this->Trackevent->query("SELECT `email` , `event_name` , `event_refer` , COUNT( `event_refer` ) AS TotalCount FROM `trackevents` WHERE DATE(`created_at`)>=curdate() AND DATE(`created_at`)<=curdate() GROUP BY `event_name`, `event_refer` ASC");
            $prev_SelectTrackEvents = $this->Trackevent->query("SELECT `email` , `event_name` , `event_refer` , COUNT( `event_refer` ) AS TotalCount FROM `trackevents` WHERE DATE(`created_at`)>= '" . $prev_arr['prev_start'] . "' AND DATE(`created_at`) <= '" . $prev_arr['prev_end'] . "' GROUP BY `event_name`, `event_refer` ASC");
        }
        $SelectTrackEvents = Common::tracks_compare($SelectTrackEvents, $prev_SelectTrackEvents);
        if (isset($this->request->data['startDate']) && isset($this->request->data['endDate'])) {
            if (strtotime($this->request->data['startDate']) == strtotime($this->request->data['endDate'])) {
                $free_user_record = $this->SubscriptionTrack->query("SELECT `stracks`.`LOGGEDIN_DATE`,COUNT(`stracks`.`COUNT`) AS FREEUSER FROM (SELECT Date( Addtime( `ls`.`last_login` , '05:30:00' ) ) AS LOGGEDIN_DATE, Count( `ls`.`id` ) AS COUNT, `ls`.`email` , `st`.`plan_id` FROM `login_sessions` AS `ls` LEFT JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` =1 WHERE DATE( ADDTIME( `ls`.`last_login` , '05:30:00' ) ) = '" . $this->request->data['startDate'] . "' AND `st`.`plan_id` IN (SELECT `os_plans`.`id` FROM `os_plans` WHERE `os_plans`.`plan_name` = 'Free' ) GROUP BY `ls`.`email` , LOGGEDIN_DATE) AS stracks GROUP BY LOGGEDIN_DATE");
                $paid_user_record = $this->SubscriptionTrack->query("SELECT `stracks`.`LOGGEDIN_DATE`,COUNT(`stracks`.`COUNT`) AS PAIDUSER FROM (SELECT Date( Addtime( `ls`.`last_login` , '05:30:00' ) ) AS LOGGEDIN_DATE, Count( `ls`.`id` ) AS COUNT, `ls`.`email` , `st`.`plan_id` FROM `login_sessions` AS `ls` LEFT JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` =1 WHERE DATE( ADDTIME( `ls`.`last_login` , '05:30:00' ) ) = '" . $this->request->data['startDate'] . "' AND `st`.`plan_id` IN ( SELECT `os_plans`.`id` FROM `os_plans` WHERE `os_plans`.`plan_name` != 'Free' ) GROUP BY `ls`.`email` , LOGGEDIN_DATE) AS stracks GROUP BY LOGGEDIN_DATE");
                $sql2 = "SELECT `stracks`.`loggedin_date`,COUNT(`stracks`.`count`) AS COUNT,SUM(`stracks`.`duration_count`) / COUNT(`stracks`.`loggedin_date`) AS `AVG_TIME` FROM (SELECT DATE(Addtime(`ls`.`last_login`, '05:30:00')) AS LOGGEDIN_DATE,COUNT(`ls`.`id`) AS COUNT, `ls`.`email`,SUM(`ls`.`session_duration`) AS duration_count FROM `login_sessions` AS `ls` INNER JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` = 1 WHERE  DATE(Addtime(`ls`.`last_login`, '05:30:00')) = '" . $this->request->data['startDate'] . "' GROUP  BY `ls`.`email`,loggedin_date) AS stracks GROUP  BY loggedin_date ";
                $records = $this->SubscriptionTrack->query($sql2);
                if (!empty($records)) {
                    $result['total'] = $records[0][0]['COUNT'];
                    $result['data'][] = array($this->request->data['startDate'], $records[0][0]['COUNT'], strtotime($this->request->data['startDate']), $records[0][0]['AVG_TIME']);
                } else {
                    $result['total'] = 0;
                    $result['data'][] = array($this->request->data['startDate'], 0, strtotime($this->request->data['startDate']), 0);
                }
            } else {
                $sql2 = "SELECT `stracks`.`loggedin_date`,COUNT(`stracks`.`count`) AS COUNT,SUM(`stracks`.`duration_count`) / COUNT(`stracks`.`loggedin_date`) AS `AVG_TIME` FROM (SELECT Date(Addtime(`ls`.`last_login`, '05:30:00')) AS LOGGEDIN_DATE,COUNT(`ls`.`id`) AS COUNT,`ls`.`email`,SUM(`ls`.`session_duration`) AS duration_count FROM `login_sessions` AS `ls` INNER JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` = '1' WHERE DATE(ADDTIME(`ls`.`last_login`, '05:30:00')) BETWEEN '" . $this->request->data['startDate'] . "' AND '" . $this->request->data['endDate'] . "' GROUP  BY `ls`.`email`,loggedin_date) AS stracks GROUP  BY loggedin_date";
                $records = $this->SubscriptionTrack->query($sql2);
                $free_user_record = $this->SubscriptionTrack->query("SELECT `stracks`.`LOGGEDIN_DATE`,COUNT(`stracks`.`COUNT`) AS FREEUSER FROM (SELECT Date( Addtime( `ls`.`last_login` , '05:30:00' ) ) AS LOGGEDIN_DATE, Count( `ls`.`id` ) AS COUNT, `ls`.`email` , `st`.`plan_id` FROM `login_sessions` AS `ls` LEFT JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` =1 WHERE DATE( ADDTIME( `ls`.`last_login` , '05:30:00' ) ) BETWEEN '" . $this->request->data['startDate'] . "' AND '" . $this->request->data['endDate'] . "' AND `st`.`plan_id` IN (SELECT `os_plans`.`id` FROM `os_plans` WHERE `os_plans`.`plan_name` = 'Free' ) GROUP BY `ls`.`email` , LOGGEDIN_DATE) AS stracks GROUP BY LOGGEDIN_DATE");
                $paid_user_record = $this->SubscriptionTrack->query("SELECT `stracks`.`LOGGEDIN_DATE`,COUNT(`stracks`.`COUNT`) AS PAIDUSER FROM (SELECT Date( Addtime( `ls`.`last_login` , '05:30:00' ) ) AS LOGGEDIN_DATE, Count( `ls`.`id` ) AS COUNT, `ls`.`email` , `st`.`plan_id` FROM `login_sessions` AS `ls` LEFT JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` =1 WHERE DATE( ADDTIME( `ls`.`last_login` , '05:30:00' ) ) BETWEEN '" . $this->request->data['startDate'] . "' AND '" . $this->request->data['endDate'] . "' AND `st`.`plan_id` IN ( SELECT `os_plans`.`id` FROM `os_plans` WHERE `os_plans`.`plan_name` != 'Free' ) GROUP BY `ls`.`email` , LOGGEDIN_DATE) AS stracks GROUP BY LOGGEDIN_DATE");
                $date_count = array();
                $avg_sess_arr = array();
                $total_count = 0;
                foreach ($records as $val) {
                    $date_count[$val['stracks']['loggedin_date']] = $val[0]['COUNT'];
                    $avg_sess_arr[$val['stracks']['loggedin_date']] = $val[0]['AVG_TIME'];
                    $total_count = $total_count + (int) $val[0]['COUNT'];
                }
                $result['total'] = $total_count;

                $date_arr = Common::getDatesFromRange($this->request->data['startDate'], $this->request->data['endDate']);
                foreach ($date_arr as $v) {
                    $x = isset($date_count[$v]) ? (int) $date_count[$v] : 0;
                    $y = isset($avg_sess_arr[$v]) ? $avg_sess_arr[$v] : 0;
                    $result['data'][] = array($v, $x, strtotime($v), $y);
                }
            }
        } else {
            $sql2 = "SELECT `stracks`.`loggedin_date`, 
                               Count(`stracks`.`count`) AS COUNT, 
                               Sum(`stracks`.`duration_count`) / Count(`stracks`.`loggedin_date`) AS 
                               `AVG_TIME`
                        FROM   (SELECT Date(Addtime(`ls`.`last_login`, '05:30:00')) AS LOGGEDIN_DATE, 
                                       Count(`ls`.`id`) AS COUNT, 
                                       `ls`.`email`, 
                                       Sum(`ls`.`session_duration`) AS duration_count 
                                       FROM `login_sessions` AS `ls`
                                INNER JOIN `subscription_tracks` AS `st` 
                                  ON (`ls`.`email` = `st`.`email` 
                                     AND `st`.`current_active` = 1 ) 
                                WHERE DATE(ADDTIME(`ls`.`last_login`, '05:30:00')) = '" . date('Y-m-d') . "'
                                GROUP  BY `ls`.`email`, 
                                          loggedin_date) AS stracks 
                        GROUP  BY loggedin_date ";
            $records = $this->SubscriptionTrack->query($sql2);
            $free_user_record = $this->SubscriptionTrack->query("SELECT `stracks`.`LOGGEDIN_DATE`,COUNT(`stracks`.`COUNT`) AS FREEUSER FROM (SELECT Date( Addtime( `ls`.`last_login` , '05:30:00' ) ) AS LOGGEDIN_DATE, Count( `ls`.`id` ) AS COUNT, `ls`.`email` , `st`.`plan_id` FROM `login_sessions` AS `ls` LEFT JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` =1 WHERE DATE( ADDTIME( `ls`.`last_login` , '05:30:00' ) ) = '" . date('Y-m-d') . "' AND `st`.`plan_id` IN (SELECT `os_plans`.`id` FROM `os_plans` WHERE `os_plans`.`plan_name` = 'Free' ) GROUP BY `ls`.`email` , LOGGEDIN_DATE) AS stracks GROUP BY LOGGEDIN_DATE");
            $paid_user_record = $this->SubscriptionTrack->query("SELECT `stracks`.`LOGGEDIN_DATE`,COUNT(`stracks`.`COUNT`) AS PAIDUSER FROM (SELECT Date( Addtime( `ls`.`last_login` , '05:30:00' ) ) AS LOGGEDIN_DATE, Count( `ls`.`id` ) AS COUNT, `ls`.`email` , `st`.`plan_id` FROM `login_sessions` AS `ls` LEFT JOIN `subscription_tracks` AS `st` ON `ls`.`email` = `st`.`email` AND `st`.`current_active` =1 WHERE DATE( ADDTIME( `ls`.`last_login` , '05:30:00' ) ) = '" . date('Y-m-d') . "' AND `st`.`plan_id` IN ( SELECT `os_plans`.`id` FROM `os_plans` WHERE `os_plans`.`plan_name` != 'Free' ) GROUP BY `ls`.`email` , LOGGEDIN_DATE) AS stracks GROUP BY LOGGEDIN_DATE");
            if (!empty($records)) {
                $result['total'] = $records[0][0]['COUNT'];
                $result['data'][] = array(date('Y-m-d'), $records[0][0]['COUNT'], strtotime(date('Y-m-d')), $records[0][0]['AVG_TIME']);
            } else {
                $result['total'] = 0;
                $result['data'][] = array(date('Y-m-d'), 0, strtotime(date('Y-m-d')), 0);
            }
        }
        $totalAvg = 0;
        foreach ($records as $key => $val) {
            $totalAvg += (float) $val[0]['AVG_TIME'];
        }
        $this->set('totavg', Common::secondsToTime($totalAvg));
        $free_user_count = array();
        $paid_user_count = array();
        $totalFreeUsercount = 0;
        $totalPaidUsercount = 0;
        foreach ($free_user_record as $k1 => $v1) {
            $free_user_count[$v1['stracks']['LOGGEDIN_DATE']] = $v1[0]['FREEUSER'];
            $totalFreeUsercount += (int) $v1[0]['FREEUSER'];
        }
        foreach ($paid_user_record as $k2 => $v2) {
            $paid_user_count[$v2['stracks']['LOGGEDIN_DATE']] = $v2[0]['PAIDUSER'];
            $totalPaidUsercount += (int) $v2[0]['PAIDUSER'];
        }
        $this->set('freeUserCount', $free_user_count);
        $this->set('paidUserCount', $paid_user_count);
        $this->set('TotalfreeUserCount', $totalFreeUsercount);
        $this->set('TotalpaidUserCount', $totalPaidUsercount);
        $SelectTrackEvents_New = array();
        foreach ($SelectTrackEvents as $key => $value) {
            $SelectTrackEvents_New[$value['trackevents']['event_name']][] = $value;
        }
        foreach ($SelectTrackEvents_New as $kk => $vv) {
            $totalClickCount = 0;
            foreach ($vv as $kkk => $vvv) {
                $totalClickCount = $totalClickCount + $vvv[0]['TotalCount'];
            }
            $SelectTrackEvents_New[$kk . "_totalcount"] = $totalClickCount;
        }
        $PieChartValueArray = array();
        $counterPlot = 0;
        if (count($SelectTrackEvents) > 0) {
            foreach ($SelectTrackEvents_New as $key1 => $value1) {
                if (is_array($value1)) {
                    $PieChartValueArray[$counterPlot]['PlotTitle'] = $key1;
                    $PieChartValueGot = "[";
                    foreach ($value1 as $key2 => $value2) {
                        $PieChartValueGot .= "{name:'" . $value2['trackevents']['event_refer'] . "',y:" . $value2[0]['TotalCount'] . "},";
                    }
                    $PieChartValueGot = substr($PieChartValueGot, 0, -1);
                    $PieChartValueGot .= "]";
                    $PieChartValueArray[$counterPlot]['PlotValue'] = $PieChartValueGot;

                    $counterPlot++;
                }
            }
        }
        $selectAllActions = $this->Trackevent->query("SELECT `event_name` FROM trackevents GROUP BY event_name");
        $this->set('LoggedInUserArray', $result);
        $this->set('fromdateset', isset($_POST['startDate']) ? $_POST['startDate'] : date('Y-m-d'));
        $this->set('todateset', isset($_POST['endDate']) ? $_POST['endDate'] : date('Y-m-d'));
        if (isset($_POST['souceSites']) && $_POST['souceSites'] != '') {
            $this->set('sourcevalset', $_POST['souceSites']);
        } else {
            $this->set('sourcevalset', 'www.orangescrum.com');
        }
        if (isset($_POST['actionSite'])) {
            $this->set('actionSite', $_POST['actionSite']);
        }
        $this->set('selectAllActions', $selectAllActions);
        $this->set('PieChartValueGot', $PieChartValueArray);
        $this->set('OriginalTrackEventCount', $SelectTrackEvents_New);
    }

    public function saveSubscriptionDetails() {
        $data = $this->request->data;
        $this->loadModel('SubscriptionTrack');
        $is_user_exists = $this->SubscriptionTrack->find('first', array('fields' => array('OsPlan.plan_name', 'OsPlan.plan_id', 'OsPlan.plan_sequence', 'SubscriptionTrack.*'), 'conditions' => array('SubscriptionTrack.email' => $data['email']), 'joins' => array(array('table' => 'os_plans', 'alias' => 'OsPlan', 'type' => 'LEFT', 'conditions' => array('OsPlan.plan_id = SubscriptionTrack.plan_id'))), 'order' => array('id' => 'DESC')));
        if (!empty($is_user_exists)) {
            $resp = array();
            if (trim($data['name']) != $is_user_exists['SubscriptionTrack']['name']) {
                //Update last subscription records of user
                try {
                    $this->SubscriptionTrack->updateAll(
                            array('SubscriptionTrack.name' => "'" . $data['name'] . "'"), array('SubscriptionTrack.email' => $data['email'])
                    );
                } catch (Exception $e) {
                    $resp['message'] = 'Exception : ' . $e->getMessage();
                }
            }

            if (strtotime(trim($data['signed_up_date'])) != strtotime($is_user_exists['SubscriptionTrack']['signed_up_date'])) {
                //Update last subscription records of user
                try {
                    $this->SubscriptionTrack->updateAll(
                            array('SubscriptionTrack.signed_up_date' => "'" . $data['signed_up_date'] . "'"), array('SubscriptionTrack.email' => $data['email'])
                    );
                } catch (Exception $e) {
                    $resp['message'] = 'Exception : ' . $e->getMessage();
                }
            }

            if (strtotime(trim($data['last_login_date'])) != strtotime($is_user_exists['SubscriptionTrack']['last_login_date'])) {
                //Update last subscription records of user
                try {
                    $this->SubscriptionTrack->updateAll(
                            array('SubscriptionTrack.last_login_date' => "'" . $data['last_login_date'] . "'"), array('SubscriptionTrack.email' => $data['email'])
                    );
                } catch (Exception $e) {
                    $resp['message'] = 'Exception : ' . $e->getMessage();
                }
            }

            if ($data['plan_id'] != $is_user_exists['SubscriptionTrack']['plan_id']) {
                //check last plan
                $plan_high_to_low = Configure::read('PLAN_SMALL_TO_LARGE');
                $current_plan_sequence = (int) $plan_high_to_low[$data['plan_id']];
                $last_plan_sequence = (int) $is_user_exists['OsPlan']['plan_sequence'];
                if ($current_plan_sequence > $last_plan_sequence) {
                    $data['upgrade_status'] = '1';
                } else {
                    $data['downgrade_status'] = '1';
                }
                //Update last subscription records of user
                try {
                    $this->SubscriptionTrack->updateAll(
                            array('SubscriptionTrack.upgrade_status' => '0', 'SubscriptionTrack.downgrade_status' => '0', 'SubscriptionTrack.current_active' => '0'), array('SubscriptionTrack.email' => $data['email'])
                    );
                } catch (Exception $e) {
                    $resp['message'] = 'Exception : ' . $e->getMessage();
                }
                $data['current_active'] = '1';
                $subscription_user['SubscriptionTrack'] = $data;
                $this->SubscriptionTrack->create();
                try {
                    $this->SubscriptionTrack->save($subscription_user);
                } catch (Exception $e) {
                    $resp['message'] = 'Exception : ' . $e->getMessage();
                }
            }
            if (count($resp) < 2) {
                $resp['message'] = 'Subscriptions updated successfully';
            }
            $resp['status'] = true;
        } else {
            $data['current_active'] = '1';
            $subscription_user['SubscriptionTrack'] = $data;
            $this->SubscriptionTrack->create();
            $this->SubscriptionTrack->save($subscription_user);
            $resp = array('status' => true, 'message' => 'Subscription records created');
        }
        echo json_encode($resp);
        exit;
    }

    public function getSubscriptionDetails() {
        if ($this->request->is('ajax')) {
            $this->loadModel('OsPlan');
            $options_free_pl_arr = array('fields' => array('OsPlan.plan_id', 'OsPlan.plan_name'), 'conditions' => array('OR' => array(
                        array('OsPlan.plan_name' => 'Free'),
                        array('OsPlan.plan_name' => 'Free Trial Expired')
            )));
            $free_pla_ids = array_keys($this->OsPlan->find('list', $options_free_pl_arr));

            $subscription_type = $this->request->data['sub_status_type'];
            $month_arr = $this->Format->get_page_month($this->request->data['mm']);
            $conditions = array();
            $conditions['and']['SubscriptionTrack.current_active'] = '1';
            $conditions['and'][] = array('DATE(SubscriptionTrack.created) <= ' => $month_arr['end_date'], 'DATE(SubscriptionTrack.created) >= ' => $month_arr['start_date']);
            $conditions['and']['SubscriptionTrack.user_type'] = 'Owner';
            $conditions['and']['SubscriptionTrack.plan_status'] = 'Active';
            if ($subscription_type == "paid") {
                $conditions['and']['NOT'] = array("SubscriptionTrack.plan_id" => $free_pla_ids);
            } elseif ($subscription_type == "free") {
                $conditions['and']['SubscriptionTrack.plan_id'] = $free_pla_ids;
                $conditions['and']['NOT'] = array("SubscriptionTrack.plan_id" => '13');
            } else if ($subscription_type == "upgraded") {
                $conditions['and']['SubscriptionTrack.upgrade_status'] = '1';
            } else if ($subscription_type == "expired") {
                $conditions['and']['SubscriptionTrack.plan_id'] = '13';
            } else {
                $conditions['and']['SubscriptionTrack.downgrade_status'] = '1';
            }
            $this->loadModel('SubscriptionTrack');
            $all_data = $this->SubscriptionTrack->find('all', array(
                'fields' => array('OsPlan.plan_name', 'OsPlan.plan_id', 'OsPlan.id', 'SubscriptionTrack.*'),
                'conditions' => $conditions,
                'joins' => array(array('table' => 'os_plans', 'alias' => 'OsPlan', 'type' => 'LEFT', 'conditions' => array('OsPlan.plan_id = SubscriptionTrack.plan_id'))),
                'order' => array('SubscriptionTrack.created DESC')
            ));
            if (is_array($all_data) && !empty($all_data)) {
                foreach ($all_data as $key => $value) {
                    if ($subscription_type == "upgraded" || $subscription_type == "downgraded") {
                        $is_user_exists = $this->SubscriptionTrack->find('all', array('fields' => array('OsPlan.plan_name', 'OsPlan.plan_id', 'OsPlan.plan_sequence', 'SubscriptionTrack.*'), 'conditions' => array('SubscriptionTrack.email' => $value['SubscriptionTrack']['email'], 'SubscriptionTrack.user_type' => 'Owner'), 'joins' => array(array('table' => 'os_plans', 'alias' => 'OsPlan', 'type' => 'LEFT', 'conditions' => array('OsPlan.plan_id = SubscriptionTrack.plan_id'))), 'order' => array('id' => 'DESC'), 'limit' => 2));
                        if (!empty($is_user_exists) && count($is_user_exists) == 2) {
                            $all_data[$key]['SubscriptionTrack']['last_plan'] = $is_user_exists[1]['OsPlan']['plan_name'];
                            $all_data[$key]['SubscriptionTrack']['last_plan_id'] = $is_user_exists[1]['OsPlan']['plan_id'];
                        }
                    }
                }
            }
            $all_data = Common::sort($all_data, '{n}.SubscriptionTrack.name', 'asc', array('type' => 'regular', 'ignoreCase' => true));
            $resp = array('status' => (count($all_data) > 0) ? true : false, 'data' => $all_data);
            echo json_encode($resp);
            exit;
        }
    }

    public function exportreport() {
        $month_range = $this->params->query['month_range'];
        $subscription_type = $this->params->query['status_type'];
        $plan_id = $this->params->query['plan_id'];
        $this->loadModel('OsPlan');
        $options_free_pl_arr = array('fields' => array('OsPlan.plan_id', 'OsPlan.plan_name'), 'conditions' => array('OR' => array(array('OsPlan.plan_name' => 'Free'), array('OsPlan.plan_name' => 'Free Trial Expired'))));
        $free_pla_ids = array_keys($this->OsPlan->find('list', $options_free_pl_arr));
        $month_arr = $this->Format->get_page_month($month_range);
        $conditions = array();
        $conditions['and']['SubscriptionTrack.current_active'] = '1';
        $conditions['and'][] = array('DATE(SubscriptionTrack.created) <= ' => $month_arr['end_date'], 'DATE(SubscriptionTrack.created) >= ' => $month_arr['start_date']);
        $conditions['and']['SubscriptionTrack.user_type'] = 'Owner';
        $conditions['and']['SubscriptionTrack.plan_status'] = 'Active';
        if ($subscription_type == "paid") {
            $conditions['and']['NOT'] = array("SubscriptionTrack.plan_id" => $free_pla_ids);
            if ($plan_id != 'All') {
                $conditions['and']['SubscriptionTrack.plan_id'] = $plan_id;
            }
        } elseif ($subscription_type == "free") {
            $conditions['and']['SubscriptionTrack.plan_id'] = $free_pla_ids;
            $conditions['and']['NOT'] = array("SubscriptionTrack.plan_id" => '13');
        } else if ($subscription_type == "upgraded") {
            $conditions['and']['SubscriptionTrack.upgrade_status'] = '1';
            if ($plan_id != 'All') {
                $conditions['and']['SubscriptionTrack.plan_id'] = $plan_id;
            }
        } else if ($subscription_type == "expired") {
            $conditions['and']['SubscriptionTrack.plan_id'] = '13';
        } else {
            $conditions['and']['SubscriptionTrack.downgrade_status'] = '1';
            if ($plan_id != 'All') {
                $conditions['and']['SubscriptionTrack.plan_id'] = $plan_id;
            }
        }
        $this->loadModel('SubscriptionTrack');
        
        $all_data = $this->SubscriptionTrack->find('all', array(
            'fields' => array('OsPlan.plan_name', 'OsPlan.plan_id', 'OsPlan.id', 'SubscriptionTrack.*', 'Lead.*'),
            'conditions' => $conditions,
            'joins' => array(array('table' => 'os_plans', 'alias' => 'OsPlan', 'type' => 'LEFT', 'conditions' => array('OsPlan.plan_id = SubscriptionTrack.plan_id')),array('table' => 'leads', 'alias' => 'Lead', 'type' => 'LEFT', 'conditions' => array('Lead.NAME = SubscriptionTrack.name','Lead.EMAIL = SubscriptionTrack.email','NOT' => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP'))))),
            'order' => array('SubscriptionTrack.created DESC')
        ));
        if (is_array($all_data) && !empty($all_data)) {
            foreach ($all_data as $key => $value) {
                $no_of_session = 0;
                $last_login = 'NA';
                $no_of_events = 0;
                if(isset($value['SubscriptionTrack']['email']) && !empty($value['SubscriptionTrack']['email'])) {
                    $this->loadModel('LoginSession');
                    $this->loadModel('Trackevent');
                    $sql = "SELECT * FROM `login_sessions` WHERE `email` = '".$value['SubscriptionTrack']['email']."' ORDER BY `id` DESC"; 
                    $data = $this->LoginSession->query($sql);
                    $last_login = !empty($data[0]['login_sessions']['last_login']) ? date('d/m/y',strtotime($data[0]['login_sessions']['last_login'])) : 'NA';
                    $no_of_session = count($data);
                    
                    $sql1 = "SELECT COUNT(`id`) AS no_of_events FROM trackevents WHERE `email` = '".$value['SubscriptionTrack']['email']."'";
                    $data1 = $this->Trackevent->query($sql1);
                    $no_of_events = $data1[0][0]['no_of_events'];
                }
                $all_data[$key]['Other']['last_login'] = $last_login;
                $all_data[$key]['Other']['no_of_session'] = $no_of_session;
                $all_data[$key]['Other']['no_of_events'] = $no_of_events;
                if ($subscription_type == "upgraded" || $subscription_type == "downgraded") {
                    $is_user_exists = $this->SubscriptionTrack->find('all', array('fields' => array('OsPlan.plan_name', 'OsPlan.plan_id', 'OsPlan.plan_sequence', 'SubscriptionTrack.*'), 'conditions' => array('SubscriptionTrack.email' => $value['SubscriptionTrack']['email'], 'SubscriptionTrack.user_type' => 'Owner'), 'joins' => array(array('table' => 'os_plans', 'alias' => 'OsPlan', 'type' => 'LEFT', 'conditions' => array('OsPlan.plan_id = SubscriptionTrack.plan_id'))), 'order' => array('id' => 'DESC'), 'limit' => 2));
                    if (!empty($is_user_exists) && count($is_user_exists) == 2) {
                        $all_data[$key]['SubscriptionTrack']['last_plan'] = $is_user_exists[1]['OsPlan']['plan_name'];
                        $all_data[$key]['SubscriptionTrack']['last_plan_id'] = $is_user_exists[1]['OsPlan']['plan_id'];
                    }
                }
            }
        }
        $all_data = Common::sort($all_data, '{n}.SubscriptionTrack.name', 'asc', array('type' => 'regular', 'ignoreCase' => true));
        if ($subscription_type == "free" || $subscription_type == "paid" || $subscription_type == "expired") {
            $final_arr['header_arr'] = array("User Name", 'User Email', 'User Type', 'Current Plan','Referer','IP','Country','Signed Up','Last LoggedIn','No. of Events','No. of Sesions');
        } else if ($subscription_type == "upgraded" || $subscription_type == "downgraded") {
            $final_arr['header_arr'] = array("User Name", 'User Email', 'User Type', 'Previous Plan', 'Current Plan','Referer','IP','Country','Signed Up','Last LoggedIn','No. of Events','No. of Sesions');
        }
        $final_data = array();
        foreach ($all_data as $k => $v) {
            $referer = isset($v['Lead']['REFER']) ? $v['Lead']['REFER'] : 'NA';
            $ip = isset($v['Lead']['IP_ADDRESS']) ? $v['Lead']['IP_ADDRESS'] : 'NA';
            $country = (isset($v['Lead']['COUNTRY']) && $v['Lead']['COUNTRY'] != '-') ? $v['Lead']['COUNTRY'] : 'NA';
            $created = isset($v['Lead']['CREATED']) ? date('d/m/y',strtotime($v['Lead']['CREATED'])) : 'NA';
            if ($subscription_type == "free" || $subscription_type == "paid" || $subscription_type == "expired") {
                $final_data[] = array($v['SubscriptionTrack']['name'], $v['SubscriptionTrack']['email'], $v['SubscriptionTrack']['user_type'], $v['OsPlan']['plan_name'], $referer, $ip, $country,$created,$v['Other']['last_login'],$v['Other']['no_of_events'],$v['Other']['no_of_session']);
            } else if ($subscription_type == "upgraded" || $subscription_type == "downgraded") {
                $last_plan = isset($v['SubscriptionTrack']['last_plan']) ? $v['SubscriptionTrack']['last_plan'] : 'NA';
                $final_data[] = array($v['SubscriptionTrack']['name'], $v['SubscriptionTrack']['email'], $v['SubscriptionTrack']['user_type'], $last_plan, $v['OsPlan']['plan_name'], $referer, $ip, $country,$created,$v['Other']['last_login'],$v['Other']['no_of_events'],$v['Other']['no_of_session']);
            }
        }
        $final_arr['data'] = $final_data;
        if ($subscription_type == "free") {
            $file_name = 'total_free_customer';
        } else if ($subscription_type == "paid") {
            $file_name = 'total_paid_customer';
        } else if ($subscription_type == "upgraded") {
            $file_name = 'total_upgraded_customer';
        } else if ($subscription_type == "downgraded") {
            $file_name = 'total_downgraded_customer';
        } else if ($subscription_type == "expired") {
            $file_name = 'total_expired_customer';
        }
        Common::export_excel($file_name, $final_arr);
        exit;
    }

    public function trackrecords() {
        if ($this->request->is('ajax')) {
            $data = $this->request->data;
            $sourcesite = trim($data['sourceSites']);
            $range_arr = explode("@@@", $data['range']);
            $firstDate = $range_arr[0];
            $lastDate = $range_arr[1];
            $this->loadModel('Pagevisit');
            $options = array('conditions' => array('Pagevisit.created_at >=' => $firstDate, 'Pagevisit.created_at <=' => $lastDate, 'Pagevisit.sourcesite' => $sourcesite),
                'fields' => array('Pagevisit.user_code', 'Pagevisit.allpagesvisit', 'Pagevisit.sourcesite', 'COUNT(Pagevisit.allpagesvisit) AS visitcount', 'Allpage.pagename'), 'order' => array('visitcount DESC'), 'group' => array('Pagevisit.allpagesvisit'));
            $options['joins'] = array(
                array('table' => 'allpages', 'alias' => 'Allpage', 'type' => 'LEFT', 'conditions' => array('Allpage.id = Pagevisit.allpagesvisit'))
            );
            $visit_data = $this->Pagevisit->find('all', $options);
            $final_array = array();
            if (!empty($visit_data)) {
                foreach ($visit_data as $key => $value) {
                    $options = array('conditions' => array('Pagevisit.allpagesvisit' => $value['Pagevisit']['allpagesvisit'], 'Pagevisit.sourcesite' => $value['Pagevisit']['sourcesite'], 'Pagevisit.created_at >=' => $firstDate, 'Pagevisit.created_at <=' => $lastDate),
                        'fields' => array('COUNT(DISTINCT(Pagevisit.user_code)) AS user_count'));
                    $visit_data = $this->Pagevisit->find('all', $options);
                    if ($value[0]['visitcount'] > 500) {
                        $priority = "<strong style='color:#FF0000;font-weight:bold;'>High</strong>";
                    } else if ($value[0]['visitcount'] > 200 && $value[0]['visitcount'] < 500) {
                        $priority = "<strong style='color:#B4A532;font-weight:bold;'>Medium</strong>";
                    } else if ($value[0]['visitcount'] > 0 && $value[0]['visitcount'] < 200) {
                        $priority = "<strong style='color:#28AF51;font-weight:bold;'>Low</strong>";
                    }

                    if (basename($value['Allpage']['pagename']) == 'www.orangescrum.com' || basename($value['Allpage']['pagename']) == 'www.andolasoft.com' || basename($value['Allpage']['pagename']) == 'www.orangescrum.org' || basename($value['Allpage']['pagename']) == 'wakeupsales.org') {
                        $pageName = "Home";
                    } else {
                        $pageName = basename($value['Allpage']['pagename']);
                        if (strpos($pageName, ".php") !== false) {
                            $pageName = substr($pageName, 0, -4);
                        } else {
                            $pageName = $pageName;
                        }
                    }
                    $final_array[] = array('priority' => $priority, 'pagename' => $pageName, "visist_count" => $value[0]['visitcount'], 'user_count' => $visit_data[0][0]['user_count']);
                }
                $resp = array('status' => true, 'data' => $final_array, 'count' => count($final_array));
            } else {
                $resp = array('status' => false, 'data' => $final_array, 'count' => 0);
            }
            echo json_encode($resp);
            exit;
        }
    }

    function versus_report() {
        $this->loadModel('Lead');
        if ($this->request->is('ajax')) {
            $this->layout = 'ajax';
            if (isset($this->request->data['startDate']) && isset($this->request->data['endDate']) && !empty($this->request->data['startDate']) && !empty($this->request->data['endDate'])) {
                $anonymous_users_count = $this->Lead->find('count', array('conditions' => array('and' => array(array('ADDTIME(Lead.CREATED, "05:30:00") >= ' => $this->request->data['startDate'] . " 00:00:00", 'ADDTIME(Lead.CREATED, "05:30:00") <= ' => $this->request->data['endDate'] . " 23:59:59"), 'Lead.NAME' => "anonymous", 'Lead.EMAIL' => "anonymous@example.com", 'Lead.SOURCE_NAME' => $this->request->data['source'], "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP'))))));
                $real_users_count = $this->Lead->find('count', array('conditions' => array('and' => array(array('ADDTIME(Lead.CREATED, "05:30:00") >= ' => $this->request->data['startDate'] . " 00:00:00", 'ADDTIME(Lead.CREATED, "05:30:00") <= ' => $this->request->data['endDate'] . " 23:59:59"), 'Lead.NAME !=' => "anonymous", 'Lead.EMAIL !=' => "anonymous@example.com", 'Lead.SOURCE_NAME' => $this->request->data['source'], "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP'))))));
                $total_users_count = $this->Lead->find('count', array('conditions' => array('and' => array(array('ADDTIME(Lead.CREATED, "05:30:00") >= ' => $this->request->data['startDate'] . " 00:00:00", 'ADDTIME(Lead.CREATED, "05:30:00") <= ' => $this->request->data['endDate'] . " 23:59:59", 'Lead.SOURCE_NAME' => $this->request->data['source'], "NOT" => array("Lead.IP_ADDRESS" => Configure::read('AS_STATIC_IP')))))));
                $anonymous_users_per = (!empty($anonymous_users_count) && !empty($total_users_count)) ? ($anonymous_users_count / $total_users_count) * 100 : 0;
                $real_users_per = (!empty($real_users_count) && !empty($total_users_count)) ? ($real_users_count / $total_users_count) * 100 : 0;
                $PieChartValue [0] = array("name" => 'Anonymous User (' . $anonymous_users_count . ')', "y" => $anonymous_users_per);
                $PieChartValue [1] = array("name" => 'Real User (' . $real_users_count . ')', "y" => $real_users_per);
                if ($anonymous_users_per == 0 && $real_users_per == 0) {
                    $json = array('data' => NULL, 'status' => 'error');
                } else {
                    $json = array('data' => $PieChartValue, 'status' => 'success');
                }
                echo json_encode($json);
                exit;
            }
        }
    }

    public function subscription_status() {
        $this->loadModel('Lead');
        $this->loadModel('SubscriptionTrack');
        $userNotLoggedInAfterFirstSignup = $this->Lead->query("SELECT `leads`.`USER_CODE`, `leads`.`SOURCE_NAME`, `leads`.`NAME`, `leads`.`EMAIL`, `leads`.`CREATED`, `subscription_tracks`.`name`, `subscription_tracks`.`email`, `subscription_tracks`.`signed_up_date`, `subscription_tracks`.`last_login_date` FROM leads, subscription_tracks WHERE `leads`.`SOURCE_NAME`='www.orangescrum.com' AND `subscription_tracks`.`email`=`leads`.`EMAIL` AND `leads`.`CREATED` > `subscription_tracks`.`last_login_date` AND `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
        $this->set("userNotLoggedInAfterFirstSignup", $userNotLoggedInAfterFirstSignup);
    }

    function user_track() {
        $this->loadModel('SubscriptionTrack');
        if ($this->request->is('ajax')) {
            $result = array();
            if (strtotime($this->request->data['startDate']) == strtotime($this->request->data['endDate'])) {
                $sql = "SELECT DATE(`subscription_tracks`.`last_login_date`) AS LOGGEDIN_DATE,COUNT(`subscription_tracks`.`id`) as COUNT from `subscription_tracks` WHERE ADDTIME(`subscription_tracks`.`last_login_date`, '05:30:00') = '" . $this->request->data['startDate'] . "' GROUP BY LOGGEDIN_DATE";
                $records = $this->SubscriptionTrack->query($sql);
                if (!empty($records)) {
                    $result['total'] = $records[0][0]['COUNT'];
                    $result['data'][] = array($this->request->data['startDate'], $records[0][0]['COUNT']);
                } else {
                    $result['total'] = 0;
                    $result['data'][] = array($this->request->data['startDate'], 0);
                }
            } else {
                $sql = "SELECT DATE(`subscription_tracks`.`last_login_date`) AS LOGGEDIN_DATE,COUNT(`subscription_tracks`.`id`) as COUNT from `subscription_tracks` WHERE ADDTIME(`subscription_tracks`.`last_login_date`, '05:30:00') BETWEEN '" . $this->request->data['startDate'] . "' AND '" . $this->request->data['endDate'] . "' GROUP BY LOGGEDIN_DATE";
                $records = $this->SubscriptionTrack->query($sql);
                $date_count = array();
                $total_count = 0;
                foreach ($records as $val) {
                    $date_count[$val[0]['LOGGEDIN_DATE']] = $val[0]['COUNT'];
                    $total_count = $total_count + (int) $val[0]['COUNT'];
                }
                $result['total'] = $total_count;
                $date_arr = Common::getDatesFromRange($this->request->data['startDate'], $this->request->data['endDate']);
                foreach ($date_arr as $v) {
                    if (isset($date_count) && isset($date_count[$v])) {
                        $x = (int) $date_count[$v];
                    } else {
                        $x = 0;
                    }
                    $result['data'][] = array($v, $x);
                }
            }
            echo json_encode($result);
            exit;
        }
    }

    public function savesessiontrack() {
        $is_private = $this->Format->checkprivateip();
        if(!$is_private){
            $this->layout = "ajax";
            $data['LoginSession']['email'] = $this->request->data['email_id'];
            $data['LoginSession']['osuser_id'] = $this->request->data['user_id'];
            $data['LoginSession']['last_login'] = $this->request->data['last_login'];
            $this->loadModel('LoginSession');
            $this->LoginSession->save($data);
            $this->loadModel('SubscriptionTrack');
            $sub_track = $this->SubscriptionTrack->find('first', array(
                'conditions' => array('SubscriptionTrack.email' => $this->request->data['email_id'], 'SubscriptionTrack.current_active' => 1)
            ));
            if (!empty($sub_track)) {
                $this->SubscriptionTrack->id = $sub_track['SubscriptionTrack']['id'];
                $this->SubscriptionTrack->saveField('last_login_date', $this->request->data['last_login']);
            }
        }
        exit;
    }

    public function updatelogoutsession() {
        $is_private = $this->Format->checkprivateip();
        if(!$is_private){
            $this->layout = "ajax";
            $this->loadModel('SubscriptionTrack');
            $sub_track = $this->SubscriptionTrack->find('first', array(
                'conditions' => array('SubscriptionTrack.email' => $this->request->data['email_id'], 'SubscriptionTrack.current_active' => 1)
            ));
            if (!empty($sub_track)) {
                $this->SubscriptionTrack->id = $sub_track['SubscriptionTrack']['id'];
                $this->SubscriptionTrack->saveField('last_logout_date', $this->request->data['last_logout_date']);
            }
            if (isset($this->request->data['user_id'])) {
                $this->loadModel('LoginSession');
                $log_track = $this->LoginSession->find('first', array('conditions' => array('LoginSession.osuser_id' => $this->request->data['user_id'], 'LoginSession.email' => $this->request->data['email_id']), 'order' => array('LoginSession.id' => 'DESC')));
                if (!empty($log_track)) {
                    $this->LoginSession->id = $log_track['LoginSession']['id'];
                    $duration = strtotime($this->request->data['last_logout_date']) - strtotime($log_track['LoginSession']['last_login']);
                    $this->LoginSession->saveField('last_logout', $this->request->data['last_logout_date']);
                    $this->LoginSession->saveField('session_duration', $duration);
                }
            }
        }
        exit;
    }

    public function sendDailyReportMail() {
        $this->loadModel('Lead');

        $site_arr = array('Orangescrum Community' => 'www.orangescrum.org', 'Orangescrum SAAS' => 'www.orangescrum.com', 'Andolasoft Site' => 'www.andolasoft.com', 'Wakeupsales Community' => 'www.wakeupsales.org');
        $content = '<table style="border:1px solid #ddd;"><tr style="text-align:left;""><th style="border-right: 1px solid #ddd;border-bottom: 1px solid #ddd;">Site</th><th style="border-bottom: 1px solid #ddd;">Total Signup/Download</th></tr>';
        foreach ($site_arr as $name => $site) {
            echo "SELECT `ID`, `USER_CODE` FROM `leads` WHERE `SOURCE_NAME`='" . $site . "' AND DATE(ADDTIME(`CREATED`, '05:30:00'))>=curdate() AND DATE(ADDTIME(`CREATED`, '05:30:00'))<=curdate() AND IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')";
            $getAllLeadsCount = $this->Lead->query("SELECT `ID`, `USER_CODE` FROM `leads` WHERE `SOURCE_NAME`='" . $site . "' AND DATE(ADDTIME(`CREATED`, '05:30:00'))>=curdate() AND DATE(ADDTIME(`CREATED`, '05:30:00'))<=curdate() AND IP_ADDRESS NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
            $content .= '<tr style="text-align:left;"><td style="border-right: 1px solid #ddd;border-bottom: 1px solid #ddd;">' . $name . '</td><td style="border-bottom: 1px solid #ddd;">' . count($getAllLeadsCount) . '</td></tr>';
        }
        $content .= '</table>';
        echo $content;
        exit;
        $this->Email->delivery = 'smtp';
        //$this->Email->to = 'madhusmita.das@andolasoft.co.in';
        $this->Email->to = 'sandeep.acharya@andolasoft.co.in';
        $this->Email->from = 'admin@leadtrack.com';
        $this->Email->subject = 'Download Information';
        $this->Email->sendAs = 'html';
        try {
            echo "email send successfully";
            $this->Sendgrid->sendgridsmtp($this->Email, $content);
        } catch (Exception $e) {
            $this->log($e->getMessage(), 'debug');
        }
        exit;
    }

    public function get_paid_count() {
        if ($this->request->is('ajax')) {
            $plan_id = $this->request->data['plan_id'];

            $start = $this->request->data['start'] . " 00:00:00";
            $end = $this->request->data['end'] . " 23:59:59";
            $this->loadModel('SubscriptionTrack');
            $free_pla_count = $this->SubscriptionTrack->query("SELECT leads.*, subscription_tracks.* FROM leads, subscription_tracks WHERE `leads`.`EMAIL`=`subscription_tracks`.`email` AND (`leads`.`SOURCE_NAME`='www.orangescrum.com' OR `leads`.`SOURCE_NAME`='app.orangescrum.com') AND `leads`.`IP_ADDRESS` NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "') AND ADDTIME(`leads`.`CREATED`, '05:30:00') >= '" . $start . "' AND ADDTIME(`leads`.`CREATED`, '05:30:00') <= '" . $end . "' AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') >= '" . $start . "' AND ADDTIME(`subscription_tracks`.`signed_up_date`, '05:30:00') <= '" . $end . "' AND subscription_tracks.`plan_status`='Active' AND subscription_tracks.`user_type`='Owner' AND subscription_tracks.`current_active`=1 AND `subscription_tracks`.`plan_id` = " . $plan_id);
            echo json_encode(array('count' => count($free_pla_count)));
            exit;
        }
    }

    public function get_lead_id(){
        if ($this->request->is('ajax')) {
            $data = $this->request->data['user_code'];
            $this->loadModel('Lead');
            $leads = $this->Lead->find('all', array('recursive' => -1, 'conditions' => array('Lead.USER_CODE' => $data)));
            echo json_encode($leads[0]['Lead']);
            exit;
        }
    }
	
	public function get_lead_reports(){
		$this->loadModel('Lead');
		$this->loadModel('Leadinfo');
		$this->loadModel('Trackevent');
		$this->loadModel('OsPlan');

		$allleads = $this->Lead->query("SELECT * FROM `leads` WHERE SOURCE_NAME LIKE '%orangescrum.com' AND DATE(CREATED) >= '2017-11-18' AND CREATED <= NOW() ORDER BY CREATED DESC");
		$columnHeader = "Name" . "\t" . "Email Id" . "\t" . "Country" . "\t" . "Time Zone" . "\t" . "Signed On" . "\t" . "Plan Status" . "\t" . "Page Visits" . "\t" . "Event Tracks" . "\t";  
		
		$setData = '';
		
		foreach($allleads as $key=>$value){
			if($value['leads']['USER_CODE'] != ''){
				$allLeadInfos = $this->Leadinfo->query("SELECT * FROM `leadinfos` WHERE `USER_CODE`='".$value['leads']['USER_CODE']."'");
				if(is_array($allLeadInfos) && count($allLeadInfos) > 0){
					$allUrls = $allLeadInfos[0]['leadinfos']['USER_URLS'];
					$allUrlsArray = json_decode($allUrls, true);
					$allFilesList = '';
					foreach($allUrlsArray['urls'] as $key1=>$value1){
						$allFilesList .= $value1['url'].', ';
					}
				}
			}
			
			if(isset($value['leads']['EMAIL']) && $value['leads']['EMAIL'] != ''){
			
				$allTrackEvents = $this->Trackevent->query("SELECT * FROM `trackevents` WHERE `email`='".$value['leads']['EMAIL']."'");
				$allEvents = '';
				foreach($allTrackEvents as $k=>$v){
					$allEvents .= $v['trackevents']['event_name']." from ".$v['trackevents']['event_refer'].', ';
				}
				
				$allPlans = $this->Trackevent->query("SELECT `op`.`plan_name` FROM `os_plans` as `op`, `subscription_tracks` as `st` WHERE `st`.`email`='".$value['leads']['EMAIL']."' AND `st`.`plan_id`=`op`.`plan_id` AND `st`.`plan_status`='Active' AND `st`.`user_type`='Owner' AND `st`.`current_active`=1");
				
				if(@$allPlans[0]['op']['plan_name'] == ''){
					$displayPlanName = 'Free';
				}else{
					$displayPlanName = $allPlans[0]['op']['plan_name'];
				}
				
				$rowData = $value['leads']['NAME'] . "\t" . $value['leads']['EMAIL'] . "\t" . $value['leads']['COUNTRY'] . "\t" . $value['leads']['TIMEZONE'] . "\t" . $value['leads']['CREATED'] . "\t" . @$displayPlanName . "\t" . @$allFilesList . "\t" . @$allEvents . "\n";
				$setData .= trim($rowData) . "\n";  
			}
		}

		header("Content-type: application/octet-stream");  
		header("Content-Disposition: attachment; filename=Lead_Detail_Reoprt_".Date('Y-m-d').".xls");  
		header("Pragma: no-cache");  
		header("Expires: 0");  
		  
		echo ucwords($columnHeader) . "\n" . $setData . "\n";  

		exit;
	}
	
	public function get_lead_reports_os_community(){
		
		$this->loadModel('Lead');
		$this->loadModel('Leadinfo');
		$this->loadModel('Trackevent');
		$this->loadModel('OsPlan');
		
		$allleads = $this->Lead->query("SELECT * FROM `leads` WHERE SOURCE_NAME LIKE '%orangescrum.org' AND DATE(CREATED) >= '2017-10-01' AND CREATED <= NOW() ORDER BY CREATED DESC");
		$columnHeader = "Email Id" . "\t" . "Downloaded On" . "\t" . "First Visited On" . "\t" . "Last Visited On" . "\t" . "No of Pages Visit" . "\t" . "No of Events Used in SAAS" . "\t" . "Page Visits" . "\t" . "Event Tracks" . "\t";  
		
		$setData = '';
		
		foreach($allleads as $key=>$value){
			if($value['leads']['USER_CODE'] != ''){
				$allLeadInfos = $this->Leadinfo->query("SELECT * FROM `leadinfos` WHERE `USER_CODE`='".$value['leads']['USER_CODE']."'");
				if(is_array($allLeadInfos) && count($allLeadInfos) > 0){
					$allUrls = $allLeadInfos[0]['leadinfos']['USER_URLS'];
					$allUrlsArray = json_decode($allUrls, true);
					$allFilesList = '';
					
					$totalElement = count($allUrlsArray['urls']);
					$ArrayLastElement = $totalElement - 1;
					
					$FirstVisitedDate = $allUrlsArray['urls'][0]['lastmodified'];
					$LastVisitedDate = $allUrlsArray['urls'][$ArrayLastElement]['lastmodified'];
					
					$timeZone = $value['leads']['TIMEZONE'];
					$explodeTimeZone = explode(":", $timeZone);
					
					$hours = $explodeTimeZone[0];
					$signofTimezone = substr($hours,0,1);
					$minutes = $explodeTimeZone[1];
					
					if($signofTimezone == "+"){
						$calculateHours = "-".substr($hours,1,2);
						$calculateMinutes = "-".$minutes;
					}else{
						$calculateHours = "+".substr($hours,1,2);
						$calculateMinutes = "+".$minutes;
					}
					
					$cenvertedFirstVisitedDateTime = date('Y-m-d H:i:s',strtotime($calculateHours.' hour '.$calculateMinutes.' minutes', strtotime($FirstVisitedDate)));
					$cenvertedLastVisitedDateTime = date('Y-m-d H:i:s',strtotime($calculateHours.' hour '.$calculateMinutes.' minutes', strtotime($LastVisitedDate)));
					
					$totalPagesVisit = 0;
					foreach($allUrlsArray['urls'] as $key1=>$value1){
						$allFilesList .= $value1['url'].', ';
						$totalPagesVisit = $totalPagesVisit + 1;
					}
				}
			}
			
			if(isset($value['leads']['EMAIL']) && $value['leads']['EMAIL'] != ''){
			
				$allTrackEvents = $this->Trackevent->query("SELECT * FROM `trackevents` WHERE `email`='".$value['leads']['EMAIL']."'");
				$totalEventsUsed = 0;
				$allEvents = '';
				foreach($allTrackEvents as $k=>$v){
					$allEvents .= $v['trackevents']['event_name']." from ".$v['trackevents']['event_refer'].', ';
					$totalEventsUsed = $totalEventsUsed + 1;
				}
				
				$rowData = $value['leads']['EMAIL'] . "\t" . $value['leads']['CREATED'] . "\t" . $cenvertedFirstVisitedDateTime .  "\t" . $cenvertedLastVisitedDateTime . "\t" . $totalPagesVisit . "\t" . $totalEventsUsed . "\t" . @$allFilesList . "\t" . @$allEvents . "\n";
				$setData .= trim($rowData) . "\n";  
			}
		}

		header("Content-type: application/octet-stream");  
		header("Content-Disposition: attachment; filename=Orangescrum_Community_Lead_Detail_Reoprt_".Date('Y-m-d').".xls");  
		header("Pragma: no-cache");  
		header("Expires: 0");  
		echo ucwords($columnHeader) . "\n" . $setData . "\n";  
		exit;
	}
    function signUpReport(){
        $this->loadModel('Lead');
        $this->loadModel('Leadinfo');
        $this->loadModel('Prospect');
        $this->loadModel('Prospectvisit');
       // echo "<pre>";print_r($_POST);print_r($this->request->data);exit;
        if($this->request->data['souceSites']){
            $selectedSource = $this->request->data['souceSites'] ;
        } else{
            $selectedSource = array("signup");
        }
        if (count($_POST) > 0 && isset($_POST['startDate'], $_POST['endDate']) && $_POST['startDate'] != '' && $_POST['endDate']) {
            $start = $_POST['startDate'] . " 00:00:00";
            $end = $_POST['endDate'] . " 23:59:59";
            if(!empty($this->request->data['souceSites'])){
                foreach($selectedSource as $ks => $vs){
                    if($vs == "signup"){
                        $total_signup_prospects = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com/signup%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='www.orangescrum.com' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
                      $this->set('totalSignupProspects', $total_signup_prospects[0][0]['count']);
                    //   $this->set('totalSignupProspects', 30);
                       $get_sign_up_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND (l.PAGE_NAME='signup' OR l.PAGE_NAME = 'signupabtest') AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                       $this->set('totalSignupleads', $get_sign_up_leads[0][0]['counts']);
                     //  $this->set('totalSignupleads', 12);
                    } else if($vs == "home"){
                        $total_home_prospects = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='www.orangescrum.com' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
                      $this->set('totalHomeProspects', $total_home_prospects[0][0]['count']);
                      // $this->set('totalHomeProspects', 60);
                       $get_home_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME='home_a_signup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                       $this->set('totalHomeleads', $get_home_leads[0][0]['counts']);
                     //  $this->set('totalHomeleads', 25);
                       $total_home_b_prospects = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com/?ref=b%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='www.orangescrum.com' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
                     $this->set('totalHomeBProspects', $total_home_b_prospects[0][0]['count']);
                    //   $this->set('totalHomeBProspects', 40);
                       $get_home_b_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='home_b_signup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                       $this->set('totalHomeBleads', $get_home_b_leads[0][0]['counts']);
                      // $this->set('totalHomeBleads', 28);
                       $get_home_popup_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='home_a_signup_popup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                       $this->set('totalHomePopupleads', $get_home_popup_leads[0][0]['counts']);
                      // $this->set('totalHomePopupleads', 28);
                       $get_home_exitpopup_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='home_a_signup_exitpopup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                         $this->set('totalHomeExitPopupleads', $get_home_exitpopup_leads[0][0]['counts']);
                     //  $this->set('totalHomeExitPopupleads', 25);
                       $get_homeb_popup_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='home_b_signup_popup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                       $this->set('totalHomeBPopupleads', $get_homeb_popup_leads[0][0]['counts']);
                     //  $this->set('totalHomeBPopupleads', 28);
                       $get_homeb_exitpopup_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='home_b_signup_exitpopup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                         $this->set('totalHomeBExitPopupleads', $get_homeb_exitpopup_leads[0][0]['counts']);
                     //  $this->set('totalHomeBExitPopupleads', 25);
                    } else if($vs == "pricing"){
                        $total_pricing_prospects = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com/pricing%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='www.orangescrum.com' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
                       $this->set('totalPricingProspects', $total_pricing_prospects[0][0]['count']);
                    //   $this->set('totalPricingProspects', 50);
                       $get_pricing_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='pricing_signup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                       $this->set('totalPricingleads', $get_pricing_leads[0][0]['counts']);
                      // $this->set('totalPricingleads', 30);
                         $get_pricing_popup_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='pricing_signup_popup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                       $this->set('totalPricingPopupleads', $get_pricing_popup_leads[0][0]['counts']);
                     //  $this->set('totalPricingPopupleads', 28);
                       $get_home_exitpopup_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND l.PAGE_NAME ='pricing_signup_exitpopup' AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                         $this->set('totalPricingExitPopupleads', $get_home_exitpopup_leads[0][0]['counts']);
                      // $this->set('totalPricingExitPopupleads', 25);
                    }
                }
            } else {
               
                $total_signup_prospects = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com/signup%' AND ADDTIME(p.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(p.CREATED, '05:30:00')<='" . $end . "' AND p.SOURCE_NAME ='www.orangescrum.com' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
                $this->set('totalSignupProspects', $total_signup_prospects[0][0]['count']);
                $get_sign_up_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND (l.PAGE_NAME='signup' OR l.PAGE_NAME = 'signupabtest') AND ADDTIME(l.CREATED, '05:30:00') >= '" . $start . "' AND ADDTIME(l.CREATED, '05:30:00')<='" . $end . "'");
                $this->set('totalSignupleads', $get_sign_up_leads[0][0]['counts']);
            }
        } else {
              $total_signup_prospects = $this->Prospect->query("SELECT COUNT(pv.id) as count FROM `prospects` as p LEFT JOIN prospectvisits as pv on p.id=pv.PROSPECTID WHERE pv.URLVISIT LIKE '%https://www.orangescrum.com/signup%' AND ADDTIME(p.CREATED, '05:30:00') >= curdate() AND ADDTIME(p.CREATED, '05:30:00')<=curdate() AND p.SOURCE_NAME ='www.orangescrum.com' AND p.IP NOT IN ('" . implode(",", Configure::read('AS_STATIC_IP')) . "')");
              $this->set('totalSignupProspects', $total_signup_prospects[0][0]['count']);
                $get_sign_up_leads = $this->Lead->query("SELECT COUNT(l.id) as counts FROM `leads` as l WHERE (`l`.`SOURCE_NAME`='www.orangescrum.com' OR `l`.`SOURCE_NAME`='app.orangescrum.com') AND (l.PAGE_NAME='signup' OR l.PAGE_NAME = 'signupabtest') AND ADDTIME(l.CREATED, '05:30:00') >= curdate() AND ADDTIME(l.CREATED, '05:30:00')<=curdate()");
                $this->set('totalSignupleads', $get_sign_up_leads[0][0]['counts']);
          }
        $this->set("selectedSource",$selectedSource);
    }
}

?>
