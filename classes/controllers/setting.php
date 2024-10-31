<?php

require_once RANKS_DIR . '/libraries/gapi.class.php';

class RanksSettingController extends RanksController {

	public $page_title = 'Ranks Setting';
	public $menu_title = 'Ranks';
	public $capability = 'edit_posts';

	public $terms = array(
		'year' => 'year',
		'month' => 'month',
		'week' => 'week',
		'day' => 'day',
	);

	public function index() {

		$terms = $this->terms;
		$patterns = $this->ranks->get_patterns();
		$accounts = $this->ranks->get_accounts();
		$analytics_profile = get_option('ranks_analytics_profile_name', false);

		$sort = array('schedule'=>array(), 'log'=>array());
		$schedule = $logs = array();

		foreach ($patterns as $key => $pattern) {
			if ($next_schedule = wp_next_scheduled("ranks_schedule_{$key}", Array( $key ))) {
				$timestamp = $next_schedule;
				$pattern_label = $pattern['label'];
				$account_label = array();
				foreach ($accounts as $slug => $account) {
					if (isset($pattern['rates'][$slug]) && $pattern['rates'][$slug] > 0) $account_label[] = $account['label'];
				}
				$schedule[] = compact('timestamp', 'pattern_label', 'account_label');
				$sort['schedule'][] = $timestamp;
			}
			if (!empty($pattern['log'])) {
				foreach ($pattern['log'] as $i => $log) {
					$method = $log['method'];
					$timestamp = $log['timestamp'];
					$label = $pattern['label'].' '.__('Total execution','ranks');
					$time = $log['processing_time'];
					$logs[] = compact('method', 'timestamp', 'key', 'label', 'time');
					$sort['log'][] = $timestamp;
				}
			}
		}
		foreach ($accounts as $slug => $account) {
			if (!empty($account['log'])) {
				foreach ($account['log'] as $i => $log) {
					$method = $log['method'];
					$timestamp = $log['timestamp'];
					$label = $account['label'].' '.__('Update data','ranks');
					$time = $log['processing_time'];
					$logs[] = compact('method', 'timestamp', 'slug', 'label', 'time');
					$sort['log'][] = $timestamp;
				}
			}
		}

		array_multisort($sort['schedule'], SORT_DESC, $schedule);
		array_multisort($sort['log'], SORT_DESC, $logs);

		return compact('terms', 'patterns', 'schedule', 'logs', 'accounts', 'analytics_mailaddress', 'analytics_profile');
	}

	public function target_new() {

		$patterns = $this->ranks->get_patterns();
		$accounts = $this->ranks->get_accounts();

		$terms = $this->terms;

		if (!empty($_POST)) {

			$key = $_POST['key'];

			if (isset($patterns[$key])) {

				$message = 2;

			} else {

				$patterns[$key] = array();
				$patterns[$key]['label'] = $_POST['label'];
				$patterns[$key]['post_type'] = $_POST['post_type'];
				$patterns[$key]['term'] = array($_POST['term']['unit']=>$_POST['term']['n']);
				$patterns[$key]['rates'] = array_map('floatval', $_POST['rates']);

				$this->ranks->set_patterns( $patterns );
				$message = 1;

			}

			wp_redirect($this->url('target_edit', array('key' => $key, 'message' => $message)));
			exit;

		} else {

			switch ($_GET['message']) {
				case 1: $message = '<div class="ranks-message">'.__('completion of a setting was carried out.','ranks').'</div>'; break;
				case 1: $message = '<div class="ranks-error">'.__('The key is already set up.','ranks').'</div>'; break;
			}

			$pattern = array(
				'label' => __('Name unset','ranks'),
				'post_type' => array('post'),
				'term' => array('month'=>1),
				'rates' => array_combine(array_keys($accounts), array_fill(0, count(array_keys($accounts)), 0)),
			);

		}

		return compact('message', 'accounts', 'key', 'terms', 'pattern');
	}

	public function target_edit() {

		$patterns = $this->ranks->get_patterns();
		$accounts = $this->ranks->get_accounts();

		$key = $_GET['key'];

		if (!isset($patterns[$key])) {
			wp_redirect($this->url('index'));
			exit;
		}

		$terms = $this->terms;

		if (!empty($_POST)) {

			if (isset($_POST['clear'])) {

				unset($patterns[$key]);
				$this->ranks->set_patterns( $patterns );
				wp_redirect($this->url('index'));
				exit;

			} else {

				$patterns[$key]['label'] = $_POST['label'];
				$patterns[$key]['post_type'] = $_POST['post_type'];
				// $patterns[$key]['posts_per_page'] = intval($_POST['posts_per_page']);
				$patterns[$key]['term'] = array($_POST['term']['unit']=>$_POST['term']['n']);
				$patterns[$key]['rates'] = array_map('floatval', $_POST['rates']);
				$patterns[$key]['rewrite_rule'] = isset($_POST['create_rewrite_rule']) && $_POST['create_rewrite_rule'] == 'create' ? $_POST['rewrite_rule'] : null;
				$patterns[$key]['schedule_event'] = isset($_POST['enable_schedule_event']) && $_POST['enable_schedule_event'] == 'enable' ? $_POST['schedule_event'] : array();

				wp_clear_scheduled_hook("ranks_schedule_{$key}", Array( $key ) );

				$this->ranks->set_patterns( $patterns );
				$message = 1;

			}

			wp_redirect($this->url(__FUNCTION__, array('message' => $message)));
			exit;

		} else {

			switch ($_GET['message']) {
				case 1: $message = '<div class="ranks-message">'.__('completion of a setting was carried out.','ranks').'</div>'; break;
			}

			$pattern = $patterns[$key];

		}

		return compact('message', 'accounts', 'key', 'terms', 'pattern');
	}

	public function target_preview() {

		$patterns = $this->ranks->get_patterns();
		$accounts = $this->ranks->get_accounts();

		$key = $_GET['key'];

		if (!isset($patterns[$key])) {
			wp_redirect($this->url('index'));
			exit;
		}

		query_posts(array(
			$this->ranks->query_var => $key,
		));


		$pattern = $patterns[$key];

		return compact('accounts', 'key', 'pattern');
	}

	public function target_score(){

		$patterns = $this->ranks->get_patterns();

		$key = $_GET['key'];

		if (!isset($patterns[$key])) {
			wp_redirect($this->url('index'));
			exit;
		}

		ini_set('memory_limit', '256M');
		set_time_limit(-1);

		$this->ranks->pattern_score($key);

		wp_redirect($this->url('index'));
		exit;
	}

	public function account_analytics() {

		$terms = $this->terms;
		$accounts = $this->ranks->get_accounts();

		$message = 0;
		$selection = array();
		$profile = null;
		$google_auth_url = null;

		if (!empty($_POST)) {

			if (isset($_POST['clear'])) {
				$accounts['analytics'] = array(
					'label' => 'Analytics',
					'status' => false,
					'auth_token' => null,
					'profile_id' => null,
					'profile_name' => null,
					'term' => array('month'=>1),
					'start_date' => null,
					'end_date' => null,
				);
				update_option('ranks_accounts', $accounts);
				$message = 9;
			} if ( !isset($accounts['analytics']['app_id'] ) || !isset($accounts['analytics']['app_secret'] ) ) {
				if( isset($_POST['app_id'] ) && isset( $_POST['app_secret'] ) ){
					if( empty($_POST['app_id'] ) || empty( $_POST['app_secret'] ) ){
						$message = 10;
					}
					else{
						$accounts['analytics']['app_id'] = $_POST['app_id'];
						$accounts['analytics']['app_secret'] = $_POST['app_secret'];
						update_option('ranks_accounts', $accounts);
						$message = 11;
					}
				}
			} elseif (isset($_POST['profile_id']) && isset($accounts['analytics']['token'])) {

				$profile_id = $_POST['profile_id'];

				$accounts['analytics']['status'] = true;
				$accounts['analytics']['profile_id'] = $profile_id;
				update_option('ranks_accounts', $accounts);
				$message = 3;

			} elseif (isset($_POST['code'])) {

				$code = $_POST['code'];

				$url = 'https://accounts.google.com/o/oauth2/token';
				$parameter = array(
					'code'				=> $code,
					'client_id'			=> $accounts['analytics']['app_id'],
					'client_secret'		=> $accounts['analytics']['app_secret'],
					'grant_type'		=> 'authorization_code',
					'redirect_uri'		=> 'urn:ietf:wg:oauth:2.0:oob',
				);
				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $parameter);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				$responce = curl_exec($ch);
				curl_close($ch);

				$token = json_decode($responce);

				if (!isset($token->error)) {
					$accounts['analytics']['status'] = false;
					$accounts['analytics']['token'] = $token;
					update_option('ranks_accounts', $accounts);
					$message = 2;
				} else {
					$message = 1;
				}

			} else {

				$accounts['analytics']['status'] = isset($_POST['enable']) && $_POST['enable'];
				$accounts['analytics']['term'] = array($_POST['term']['unit']=>$_POST['term']['n']);
				update_option('ranks_accounts', $accounts);
				$message = 3;

			}

			wp_redirect($this->url(__FUNCTION__, array('message' => $message)));
			exit;

		} else {

			switch ($_GET['message']) {
				case 1: $message = '<div class="ranks-error">'.__('attestation code is not right.','ranks').'</div>'; break;
				case 2: $message = '<div class="ranks-message">'.__('choose the profile which can acquire PV of this site.','ranks').'</div>'; break;
				case 3: $message = '<div class="ranks-message">'.__('completion of a setting was carried out.','ranks').'</div>'; break;
				case 9: $message = '<div class="ranks-message">'.__('Setting was deleted.','ranks').'</div>'; break;
				case 10: $message = '<div class="ranks-error">'.__('Google API Client ID and Client Secret are indispensable.','ranks').'</div>'; break;
        case 11: $message = '<div class="ranks-message">'.__('Google API Client setup was saved.','ranks').'</div>'; break;
			}

			if ( !isset($accounts['analytics']['app_id'] ) || !isset($accounts['analytics']['app_secret'] ) ) {

			}
			else if (isset($accounts['analytics']['token'])) {

				$token = $accounts['analytics']['token'];

				$url = 'https://www.googleapis.com/analytics/v3/management/accounts/~all/webproperties/~all/profiles';
				$parameter = array(
					'key' => $accounts['analytics']['app_id'],
				);
				$url.='?'.http_build_query($parameter);

				$header = array(
					'Authorization: '.$token->token_type.' '.$token->access_token
				);

				$ch = curl_init($url);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				$responce = curl_exec($ch);
				curl_close($ch);

				$profiles = json_decode($responce);

				$selection = array();
				if (!empty($profiles->items)) {
					foreach ($profiles->items as $item) {
						$selection[$item->id] = array(
							'profile_name' => $item->name,
							'property_id' => $item->webPropertyId,
							'website_url' => $item->websiteUrl,
						);
					}
				}

				if (isset($accounts['analytics']['profile_id'])) {
					$profile_id = $accounts['analytics']['profile_id'];
					$profile = isset($selection[$profile_id]) ? $selection[$profile_id] : null;
				}

			}

			$google_auth_url = 'https://accounts.google.com/o/oauth2/auth';
			$parameter = array(
				'response_type'		=> 'code',
				'client_id'			=> $accounts['analytics']['app_id'],
				'redirect_uri'		=> 'urn:ietf:wg:oauth:2.0:oob',
				'scope'				=> 'https://www.googleapis.com/auth/analytics.readonly',
				'state'				=> 'test',
				'approval_prompt'	=> 'auto',
			);
			$google_auth_url.='?'.http_build_query($parameter);

		}

		return compact('message', 'terms', 'accounts', 'selection', 'profile', 'google_auth_url');
	}

	public function account_facebook() {

		$accounts = $this->ranks->get_accounts();

		if (!empty($_POST)) {

			$accounts['facebook']['status']		= isset($_POST['enable']) && $_POST['enable'];
			$accounts['facebook']['app_id']		= urlencode( $_POST['app_id'] );
			$accounts['facebook']['app_secret']	= urlencode( $_POST['app_secret'] );

			update_option('ranks_accounts', $accounts);
			$message = 1;

			wp_redirect($this->url(__FUNCTION__, array('message' => $message)));
			exit;

		} else {

			switch ($_GET['message']) {
				case 1: $message = '<div class="ranks-message">'.__('completion of a setting was carried out.','ranks').'</div>'; break;
			}

		}

		return compact('message', 'accounts');
	}

	public function account_twitter() {

		$accounts = $this->ranks->get_accounts();

		if (!empty($_POST)) {

			$accounts['twitter']['status'] = isset($_POST['enable']) && $_POST['enable'];
			update_option('ranks_accounts', $accounts);
			$message = 1;

			wp_redirect($this->url(__FUNCTION__, array('message' => $message)));
			exit;

		} else {

			switch ($_GET['message']) {
				case 1: $message = '<div class="ranks-message">'.__('completion of a setting was carried out.','ranks').'</div>'; break;
			}

		}

		return compact('message', 'accounts');
	}

	public function account_preview() {

		$patterns = $this->ranks->get_patterns();
		$accounts = $this->ranks->get_accounts();

		$account_slug = $_GET['account'];

		if (!isset($accounts[$account_slug])) {
			wp_redirect($this->url('index'));
			exit;
		}

		$post_type = array();
		foreach ($patterns as $pattern) {
			$post_type = array_merge($post_type, $pattern['post_type']);
		}
		$post_type = array_unique($post_type);

		query_posts(array(
			'post_type' => $post_type,
			'posts_per_page' => -1,
			'post_status' => 'publish',
			'meta_key' => "ranks_{$account_slug}_count",
			'meta_query' => array(
				array(
					'key' => "ranks_{$account_slug}_count",
					'value' => '0',
					'compare' => '>',
				),
			),
			'orderby' => 'meta_value_num',
			'order' => 'desc',
		));

		return compact('accounts', 'account_slug');
	}

	public function account_count() {

		$accounts = $this->ranks->get_accounts();

		$account_slug = $_GET['account'];
		if (!isset($accounts[$account_slug])) {
			wp_redirect($this->url('index'));
			exit;
		}

		ini_set('memory_limit', '256M');
		set_time_limit(-1);

//		$timestamp = current_time('timestamp');
		$this->ranks->account_count($account_slug);
//		$processing_time = current_time('timestamp') - $timestamp;
//		$method = 'manual';
//
//		if (empty($accounts[$account_slug]['log'])) $accounts[$account_slug]['log'] = array();
//		array_unshift($accounts[$account_slug]['log'], compact('timestamp', 'processing_time', 'method'));

		wp_redirect($this->url('index'));
		exit;
	}

}
