<?php
/*
Plugin Name: My Custom Google Login
Description: Professional Google Login for WooCommerce.
Version: 2.0
Author: Your Name
*/

if (!defined('ABSPATH')) exit;

// আপনার সঠিক ক্রেডেনশিয়ালস সেট করা হলো
define('G_LOGIN_CLIENT_ID', '743767019147-qn57mgpa4kk4f9hag91bksi2s7ibpkcf.apps.googleusercontent.com');
define('G_LOGIN_CLIENT_SECRET', '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');
define('G_LOGIN_REDIRECT_URI', 'https://dev-digital-marketing-email.pantheonsite.io/?action=google_callback');

// ১. WooCommerce লগইন ফর্মে সুন্দর একটি বাটন যোগ করা
add_action('woocommerce_login_form_end', 'render_custom_google_login_button');
function render_custom_google_login_button() {
    $auth_url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
        'client_id'     => G_LOGIN_CLIENT_ID,
        'redirect_uri'  => G_LOGIN_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'email profile',
        'access_type'   => 'online',
        'prompt'        => 'select_account'
    ]);

    echo '<div style="margin: 20px 0; text-align: center;">
            <div style="border-top: 1px solid #ddd; margin-bottom: 20px; position: relative;">
                <span style="background: #fff; padding: 0 10px; position: absolute; top: -12px; left: 50%; transform: translateX(-50%); color: #888; font-size: 14px;">Or login with</span>
            </div>
            <a href="' . esc_url($auth_url) . '" style="background: #ffffff; color: #444; border: 1px solid #747775; padding: 10px 16px; text-decoration: none; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center; font-weight: 500; font-family: Roboto, arial, sans-serif; width: 100%; transition: background 0.3s;">
                <img src="https://upload.wikimedia.org/wikipedia/commons/5/53/Google_%22G%22_Logo.svg" style="width: 20px; margin-right: 12px;">
                Sign in with Google
            </a>
          </div>';
}

// ২. গুগল থেকে আসা ডাটা প্রসেস এবং লগইন হ্যান্ডেল করা
add_action('template_redirect', 'handle_google_callback_process');
function handle_google_callback_process() {
    if (isset($_GET['action']) && $_GET['action'] == 'google_callback' && isset($_GET['code'])) {
        
        // এক্সচেঞ্জ কোড ফর টোকেন
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'code'          => $_GET['code'],
                'client_id'     => G_LOGIN_CLIENT_ID,
                'client_secret' => G_LOGIN_CLIENT_SECRET,
                'redirect_uri'  => G_LOGIN_REDIRECT_URI,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        $token_data = json_decode(wp_remote_retrieve_body($response));

        if (isset($token_data->access_token)) {
            // ইউজারের তথ্য সংগ্রহ
            $user_info_url = "https://www.googleapis.com/oauth2/v3/userinfo?access_token=" . $token_data->access_token;
            $user_info_resp = wp_remote_get($user_info_url);
            $google_user = json_decode(wp_remote_retrieve_body($user_info_resp));

            if (isset($google_user->email)) {
                $email = sanitize_email($google_user->email);
                $user = get_user_by('email', $email);

                // যদি ইউজার আগে থেকে না থাকে তবে নতুন একাউন্ট তৈরি
                if (!$user) {
                    $username = current(explode('@', $email)) . rand(100, 999);
                    $password = wp_generate_password();
                    $user_id = wp_create_user($username, $password, $email);
                    
                    if (is_wp_error($user_id)) {
                        wp_die("User creation failed: " . $user_id->get_error_message());
                    }
                    
                    $user = get_user_by('id', $user_id);
                    
                    // ইউজারের ফার্স্ট নেম এবং লাস্ট নেম আপডেট করা (ঐচ্ছিক)
                    wp_update_user([
                        'ID' => $user->ID,
                        'first_name' => $google_user->given_name ?? '',
                        'last_name' => $google_user->family_name ?? ''
                    ]);
                }

                // ইউজারকে লগইন করানো
                wp_clear_auth_cookie();
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
                
                // My Account পেজে রিডাইরেক্ট (Pantheon ক্যাশ এড়িয়ে)
                $redirect_to = get_permalink(get_option('woocommerce_myaccount_page_id'));
                wp_redirect(add_query_arg('login', 'success', $redirect_to));
                exit;
            }
        } else {
            wp_die("গুগল অথেন্টিকেশন ব্যর্থ হয়েছে। আপনার Client Secret বা Redirect URI পুনরায় চেক করুন।");
        }
    }
}
