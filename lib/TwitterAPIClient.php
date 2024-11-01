<?php
/**
 * Super-skeletal class to interact with Twitter from a WordPress plugin.
 */

// Loads OAuth consumer class via OAuthWP class.
require_once 'OAuthWP_Twitter.php';

class Twitter_API_Client extends Twitter_OAuthWP_Plugin {
    private $api_key; //< Also the "Consumer key" the user entered.

    function __construct ($consumer_key = '', $consumer_secret = '') {
        $this->client = new OAuthWP_Twitter;
        $this->client->server = 'Twitter';
        $this->client->client_id = $consumer_key;
        $this->client->client_secret = $consumer_secret;
        $this->client->configuration_file = dirname(__FILE__) . '/oauth_api/oauth_configuration.json';
        $this->client->Initialize();

        return $this;
    }

    // Needed for some GET requests.
    public function setApiKey ($key) {
        $this->api_key = $key;
    }

    public function verifyCredentials () {
        return $this->talkToService('account/verify_credentials.json', array(), 'GET');
    }

    public function getTwitterDataFor ($who) {
        return $this->talkToService('users/show.json?user_id=' . $who, array(), 'GET');
    }
}
