<?php

/**
 * Name: public_server
 * Description: Friendica plugin/addon with functions suitable for a public server.
 * Version: 1.1
 * Author: Keith Fernie <http://friendika.me4.it/profile/keith>
 */




function public_server_install() {

	register_hook('register_account', 'addon/public_server/public_server.php', 'public_server_register_account');
	register_hook('cron', 'addon/public_server/public_server.php', 'public_server_cron');
        register_hook('enotify','addon/public_server/public_server.php', 'public_server_enotify');
	register_hook('logged_in', 'addon/public_server/public_server.php', 'public_server_login');
}


function public_server_uninstall() {

	unregister_hook('register_account', 'addon/public_server/public_server.php', 'public_server_register_account');
	unregister_hook('cron', 'addon/public_server/public_server.php', 'public_server_cron');
        unregister_hook('enotify','addon/public_server/public_server.php', 'public_server_enotify');
	unregister_hook('logged_in', 'addon/public_server/public_server.php', 'public_server_login');
}

function public_server_register_account($a,$b) {

	$uid = $b;

	$days = get_config('public_server','expiredays');
	$days_posts = get_config('public_server','expireposts');
	if(! $days)
		return;

	$r = q("UPDATE user set account_expires_on = '%s', expire = %d where uid = %d",
		dbesc(datetime_convert('UTC','UTC','now +' . $days . ' days')),
		intval($days_posts),
		intval($uid)
	);

};


function public_server_cron($a,$b) {
	logger("public_server: cron start");

	require_once('include/enotify.php');
	$r = q("select * from user where account_expires_on < UTC_TIMESTAMP() + INTERVAL 5 DAY and account_expires_on > '0000-00-00 00:00:00' and
		expire_notification_sent = '0000-00-00 00:00:00' ");

	if(count($r)) {
		foreach($r as $rr) {
			notification(array(
				'uid' => $rr['uid'],
				'type' => NOTIFY_SYSTEM,
				'system_type' => 'public_server_expire',
				'language'     => $rr['language'],
				'to_name'      => $rr['username'],
				'to_email'     => $rr['email'],
				'source_name'  => t('Administrator'),
				'source_link'  => $a->get_baseurl(),
				'source_photo' => $a->get_baseurl() . '/images/person-80.jpg',
			));

			q("update user set expire_notification_sent = '%s' where uid = %d",
				dbesc(datetime_convert()),
				intval($rr['uid'])
			);
		}
	}

	$r = q("select * from user where account_expired = 1 and account_expires_on < UTC_TIMESTAMP() - INTERVAL 5 DAY and account_expires_on > '0000-00-00 00:00:00'");
	if(count($r)) {
		require_once('include/Contact.php');
		foreach($r as $rr)
			user_remove($rr['uid']);

	}
	$nologin = get_config('public_server','nologin');
	if($nologin) {
		$r = q("select uid from user where account_expired = 0 and login_date = '0000-00-00 00:00:00' and register_date <  UTC_TIMESTAMP() - INTERVAL %d DAY and account_expires_on = '0000-00-00 00:00:00'",intval($nologin));
		if(count($r)) {
			foreach($r as $rr)
				q("update user set account_expires_on = '%s' where uid = %d",
					dbesc(datetime_convert('UTC','UTC','now +' . '6 days')),
					intval($rr['uid'])
			);
		}
        }


        $flagusers = get_config('public_server','flagusers');
	if($flagusers) {
		$r = q("select uid from user where account_expired = 0 and login_date < UTC_TIMESTAMP() - INTERVAL %d DAY and account_expires_on = '0000-00-00 00:00:00' and `page-flags` = 0",intval($flagusers));
		if(count($r)) {
			foreach($r as $rr)
				q("update user set account_expires_on = '%s' where uid = %d",
					dbesc(datetime_convert('UTC','UTC','now +' . '6 days')),
					intval($rr['uid'])
				);
		}
        }

        $flagposts = get_config('public_server','flagposts');
        $flagpostsexpire = get_config('public_server','flagpostsexpire');
	if ($flagposts && $flagpostsexpire) {
		$r = q("select uid from user where account_expired = 0 and login_date < UTC_TIMESTAMP() - INTERVAL %d DAY and account_expires_on = '0000-00-00 00:00:00' and expire = 0 and `page-flags` = 0",intval($flagposts));
		if(count($r)) {
			foreach($r as $rr)
				q("update user set expire = %d where uid = %d",
					intval($flagpostsexpire),
					intval($rr['uid'])
				);
		}
        }

	logger("public_server: cron end");

}

function public_server_enotify(&$a, &$b) {
    if (x($b, 'params') && $b['params']['type'] == NOTIFY_SYSTEM 
		&& x($b['params'], 'system_type') && $b['params']['system_type'] === 'public_server_expire') {
        $b['itemlink'] = $a->get_baseurl();
        $b['epreamble'] = $b['preamble'] = sprintf( t('Your account on %s will expire in a few days.'), get_config('system','sitename'));
        $b['subject'] = t('Your Friendica account is about to expire.');
        $b['body'] = sprintf( t("Hi %1\$s,\n\nYour account on %2\$s will expire in less than five days. You may keep your account by logging in at least once every 30 days"), $b['params']['to_name'], "[url=" . $app->config["system"]["url"] . "]" . $app->config["sitename"] . "[/url]");
    }
}

function public_server_login($a,$b) {
	$days = get_config('public_server','expiredays');
	if(! $days)
		return;
	$r = q("UPDATE user set account_expires_on = '%s' where uid = %d and account_expires_on > '0000-00-00 00:00:00'",
	dbesc(datetime_convert('UTC','UTC','now +' . $days . ' days')),
	local_user()
	);
}

function public_server_plugin_admin_post ( &$a ) {
    check_form_security_token_redirectOnErr('/admin/plugins/publicserver', 'publicserver');
    $expiredays = (( x($_POST, 'expiredays') ) ? notags(trim($_POST['expiredays'] )) : '');
    $expireposts = (( x($_POST, 'expireposts') ) ? notags(trim($_POST['expireposts'] )) : '');
    $nologin = (( x($_POST, 'nologin') ) ? notags(trim($_POST['nologin'] )) : '');
    $flagusers = (( x($_POST, 'flagusers') ) ? notags(trim($_POST['flagusers'] )) : '');
    $flagposts = (( x($_POST, 'flagposts') ) ? notags(trim($_POST['flagposts'] )) : '');
    $flagpostsexpire = (( x($_POST, 'flagpostsexpire') ) ? notags(trim($_POST['flagpostsexpire'] )) : '');
    set_config( 'public_server','expiredays',$expiredays );
    set_config( 'public_server','expireposts',$expireposts );
    set_config( 'public_server','nologin',$nologin );
    set_config( 'public_server','flagusers',$flagusers);
    set_config( 'public_server','flagposts',$flagposts );
    set_config( 'public_server','flagpostsexpire',$flagpostsexpire );
    info( t('Settings saved').EOL );
}
function public_server_plugin_admin ( &$a, &$o) {
    $token = get_form_security_token("publicserver");
    $t = get_markup_template( "admin.tpl", "addon/public_server");
    $o = replace_macros($t, array(
	'$submit' => t('Save Settings'),
	'$form_security_token' => $token,
	'$infotext' => t('Set any of these options to 0 to deactivate it.'),
	'$expiredays' => Array( "expiredays","Expire Days", intval(get_config('public_server', 'expiredays')), "When an account is created on the site, it is given a hard "),
	'$expireposts' => Array( "expireposts", "Expire Posts", intval(get_config('public_server','expireposts')), "Set the default days for posts to expire here"),
	'$nologin' => Array( "nologin", "No Login", intval(get_config('public_server','nologin')), "Remove users who have never logged in after nologin days "),
	'$flagusers' => Array( "flagusers", "Flag users", intval(get_config('public_server','flagusers')), "Remove users who last logged in over flagusers days ago"),
	'$flagposts' => Array( "flagposts", "Flag posts", intval(get_config('public_server','flagposts')), "For users who last logged in over flagposts days ago set post expiry days to flagpostsexpire "),
	'$flagpostsexpire' => Array( "flagpostsexpire", "Flag posts expire", intval(get_config('public_server','flagpostsexpire'))),
    ));
}

