<?php
/*
Plugin Name:  WPAuth
Plugin URI:   https://github.com/orcnd 
Description:  A simple plugin to authenticate users against a WordPress database.
Version:      1.0
Author:       orcnd 
Author URI:   https://github.com/orcnd
License:      GPL2
License URI:  https://www.gnu.org/licenses/gpl-2.0.html
*/
if (!class_exists('WP_REST_Controller')) {
    require_once ABSPATH .
        'wp-content/plugins/rest-api/lib/endpoints/' .
        '/class-wp-rest-controller.php';
}
if (!class_exists('WP_REST_Taxonomies_Controller')) {
    require_once ABSPATH .
        'wp-content/plugins/rest-api/lib/endpoints/' .
        '/class-wp-rest-terms-controller.php';
}
if (!function_exists('add_action')) {
    echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
    exit();
}

class wpAuth extends WP_REST_Controller
{
    /**
     * @var string $cacheTime cache time in seconds for token
     */
    var $cacheTime = 30;

    /**
     * @var string $controlTokenExpireTime control token expire time in seconds
     */
    var $controlTokenExpireTime = 10;

    /**
     * construct class
     *
     * @return void
     */
    public function __construct()
    {
        $this->namespace = 'wpauth/v1';
    }

    /**
     * settings for plugin
     *
     * @var array $registerSettings
     */
    var $registerSettings = [
        'key' => [
            'name' => 'Key',
            'type' => 'password',
            'text' => 'wpAuth Access Key',
        ],
        'usernamePrefix' => [
            'name' => 'UsernamePrefix',
            'type' => 'text',
            'text' => 'wpAuth Username Prefix',
        ],
        'redirectUrl' => [
            'name' => 'RedirectUrl',
            'type' => 'text',
            'text' => 'wpAuth Redirect Url',
        ],
        'passwordSalt' => [
            'name' => 'PasswordSalt',
            'type' => 'text',
            'text' => 'wpAuth Password Salt',
        ],
    ];

    /**
     * create redirect code with shortcode
     *
     * @return void
     */
    function shortcode()
    {
        $token = isset($_GET['token']) ? $_GET['token'] : '';
        if ($token !== '') {
            $userData = get_transient('wpAuthByOrcnd' . $token);
            if ($userData !== false) {
                $userNamePrefix = get_option(
                    'wpAuthByOrcndSettingUsernamePrefix'
                );

                $existingUser = get_user_by(
                    'login',
                    $userNamePrefix . $userData['name']
                );

                if ($existingUser === false) {
                    $newUser = [
                        'user_login' => $userNamePrefix . $userData['name'],
                        'user_pass' => $this->generatePass($userData['email']),
                        'user_email' => (string) $userData['email'],
                        'display_name' => (string) $userData['name'],
                    ];
                    wp_insert_user($newUser);
                }

                $user = wp_signon(
                    [
                        'user_login' =>
                            $userNamePrefix . (string) $userData['name'],
                        'user_password' => $this->generatePass(
                            $userData['email']
                        ),
                    ],
                    false
                );

                delete_transient('wpAuthByOrcnd' . $token);

                $redirectUrl = get_option('wpAuthByOrcndSettingRedirectUrl');

                return "<p><a href=\"{$redirectUrl}\">Click Here to Redirect</a></p><script> window.location=\"{$redirectUrl}\" </script>";
            } else {
                return '<p>invalid token</p>';
            }
        } else {
            return '<p>invalid req</p>';
        }
    }
    /**
     * generate password for users
     *
     * @return string password
     */
    function generatePass($e)
    {
        $passwordSalt = get_option('wpAuthByOrcndSettingPasswordSalt');
        return md5(((string) $e) . $passwordSalt);
    }

    /**
     * initialize plugin
     *
     * @return void
     */
    function adminInit()
    {
        //adding settings in bulk
        foreach ($this->registerSettings as $setting) {
            //registering settings
            register_setting(
                'general',
                'wpAuthByOrcndSetting' . $setting['name']
            );
            //adding fields
            add_settings_field(
                'wpAuthByOrcnd' . $setting['name'] . 'Field',
                $setting['text'],
                [$this, 'fieldCallback'],
                'general',
                'default',
                ['setting' => $setting]
            );
        }
    }

    /**
     * field output for admin page
     *
     * @param array $args
     * @return void
     */
    function fieldCallback(array $arg)
    {
        echo $this->settingInput(
            $arg['setting']['type'],
            'wpAuthByOrcndSetting' . $arg['setting']['name'],
            get_option('wpAuthByOrcndSetting' . $arg['setting']['name'])
        );
    }

    /**
     * create form input for settings
     *
     * @return string
     */
    function settingInput($type, $name, $data)
    {
        $str = "<input type=\"{$type}\" name=\"{$name}\" value=\"";
        $str .= (isset($data) ? esc_attr($data) : '') . '">';
        return $str;
    }

    /**
     * initialize menu items
     *
     * @return void
     */
    function menuInit()
    {
        add_menu_page(
            'wpAuth', // page title
            'wpAuth', // menu title
            'manage_options', // capability
            'wpAuthByOrcnd', // menu slug
            [$this, 'adminPage'] // callback function
        );
    }

    /**
     * initialize and create rest routes
     *
     * @return void
     */
    public function restInit()
    {
        register_rest_route($this->namespace, '/token', [
            'methods' => 'POST',
            'callback' => [$this, 'routeToken'],
            'args' => [
                'email' => [
                    'required' => true,
                ],
                'time' => [
                    'required' => true,
                ],
                'control_token' => [
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/generateLogin', [
            'methods' => 'POST',
            'callback' => [$this, 'routeLogin'],

            'args' => [
                'email' => [
                    'required' => true,
                ],
                'name' => [
                    'required' => true,
                ],
                'access_token' => [
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * check api working
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Response
     */
    public function routeToken($request)
    {
        $params = $request->get_params();
        $controlTokenTime = strtotime($params['time']);
        //check if token expired
        if (
            $controlTokenTime > time() + $this->controlTokenExpireTime ||
            $controlTokenTime < time() - $this->controlTokenExpireTime
        ) {
            return new WP_REST_Response(
                [
                    'error' => 'control token expired',
                ],
                400
            );
        }

        $controlToken = $this->createAccessToken(
            $params['email'],
            $params['time'],
            'control_token'
        );
        if ($controlToken === $params['control_token']) {
            $accessTime = date('c', time());
            $accessToken = $this->createAccessToken(
                $params['email'],
                $accessTime,
                'access_token'
            );
            set_transient(
                'wpAuthByOrcnd' . $accessToken,
                [$params['email'], $accessTime],

                $this->cacheTime
            );
            return new WP_REST_Response(
                ['access_token' => $accessToken, 'time' => $accessTime],
                200
            );
        } else {
            return new WP_REST_Response(
                [
                    'error' => 'invalid token',
                ],
                400
            );
        }
    }

    /**
     * create login link for user
     *
     * @param WP_REST_Request $request Full data about the request.
     * @return WP_Error|WP_REST_Response
     */
    function routeLogin($request)
    {
        $params = $request->get_params();
        $cachedData = get_transient('wpAuthByOrcnd' . $params['access_token']);
        if ($cachedData === false) {
            return new WP_REST_Response(['error' => 'invalid token'], 400);
        }
        if ($cachedData[0] !== $params['email']) {
            return new WP_REST_Response(['error' => 'invalid token'], 400);
        }
        if (strtotime($cachedData[1]) > time() + $this->cacheTime) {
            return new WP_REST_Response(['error' => 'token expired'], 400);
        }

        $uniqueId = $this->uniqidReal();
        set_transient(
            'wpAuthByOrcnd' . $uniqueId,
            ['email' => $params['email'], 'name' => $params['name']],
            $this->cacheTime
        );
        return new WP_REST_Response(
            [
                'login' => $uniqueId,
            ],
            200
        );
    }

    /**
     * create access token for email
     *
     * @param string $email email address
     * @param string $time time of token
     * @param string $subject subject of token
     * @return string access token
     */
    function createAccessToken($email, $time, $subject = '')
    {
        $key = get_option('wpAuthByOrcndSettingKey');
        return md5($key . $email . $time . $subject);
    }

    /**
     * generates unique id (source: https://www.php.net/manual/en/function.uniqid.php)
     *
     * @param int $lenght lenght of id
     * @return string
     */
    function uniqidReal($lenght = 13)
    {
        // uniqid gives 13 chars, but you could adjust it to your needs.
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(ceil($lenght / 2));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(ceil($lenght / 2));
        } else {
            throw new Exception(
                'no cryptographically secure random function available'
            );
        }
        return substr(bin2hex($bytes), 0, $lenght);
    }

    /**
     * get client ip
     *
     * @return string
     */
    public static function getClientIP()
    {
        if (isset($_SERVER)) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                return $_SERVER['HTTP_X_FORWARDED_FOR'];
            }

            if (isset($_SERVER['HTTP_CLIENT_IP'])) {
                return $_SERVER['HTTP_CLIENT_IP'];
            }

            return $_SERVER['REMOTE_ADDR'];
        }

        if (getenv('HTTP_X_FORWARDED_FOR')) {
            return getenv('HTTP_X_FORWARDED_FOR');
        }

        if (getenv('HTTP_CLIENT_IP')) {
            return getenv('HTTP_CLIENT_IP');
        }

        return getenv('REMOTE_ADDR');
    }
}

$wpAuth = new wpAuth();
add_action('rest_api_init', [$wpAuth, 'restInit']);
add_action('admin_init', [$wpAuth, 'adminInit']);
add_shortcode('wpAuth', [$wpAuth, 'shortcode']);
