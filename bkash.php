<?php
class Bkash extends NonmerchantGateway {
    private $currency;

    public function __construct() {
        Loader::loadComponents($this, ['Input']);
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');
    }

    public function setCurrency($currency) {
        $this->currency = $currency;
    }

    public function setMeta(?array $meta = null) {
        $this->meta = (object)($meta ?? []);
    }

    public function getName() {
        return "bKash";
    }

    public function getSettings(array $meta = null) {
        return [
            'username' => [
                'label' => Language::_('Bkash.settings.username', true),
                'type' => 'text',
                'value' => isset($meta['username']) ? $meta['username'] : ''
            ],
            'password' => [
                'label' => Language::_('Bkash.settings.password', true),
                'type' => 'password',
                'value' => isset($meta['password']) ? $meta['password'] : ''
            ],
            'app_key' => [
                'label' => Language::_('Bkash.settings.app_key', true),
                'type' => 'text',
                'value' => isset($meta['app_key']) ? $meta['app_key'] : ''
            ],
            'app_secret' => [
                'label' => Language::_('Bkash.settings.app_secret', true),
                'type' => 'password',
                'value' => isset($meta['app_secret']) ? $meta['app_secret'] : ''
            ]
        ];
    }

    public function editSettings(array $meta) {
        $rules = [
            'username' => [
                'valid' => [
                    'rule' => ['isEmpty'],
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.username.empty', true)
                ]
            ],
            'password' => [
                'valid' => [
                    'rule' => ['isEmpty'],
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.password.empty', true)
                ]
            ],
            'app_key' => [
                'valid' => [
                    'rule' => ['isEmpty'],
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.app_key.empty', true)
                ]
            ],
            'app_secret' => [
                'valid' => [
                    'rule' => ['isEmpty'],
                    'negate' => true,
                    'message' => Language::_('Bkash.!error.app_secret.empty', true)
                ]
            ]
        ];
        $this->Input->setRules($rules);
        if ($this->Input->validates($meta)) {
            return [
                'username' => (string)$meta['username'],
                'password' => (string)$meta['password'],
                'app_key' => (string)$meta['app_key'],
                'app_secret' => (string)$meta['app_secret']
            ];
        }
        return [];
    }

    public function encryptableFields() {
        return ['password', 'app_secret'];
    }

    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null) {
        $token = $this->grantToken();
        if (!$token) {
            $this->Input->setErrors(['token' => ['error' => Language::_('Bkash.!error.token_failed', true)]]);
            return false;
        }

        $payment_data = [
            'amount' => $amount,
            'currency' => $this->currency ?? 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => 'INV' . time(),
            'callbackURL' => Configure::get('Blesta.gw_callback_url') . Configure::get('Blesta.company_id') . '/bkash/'
        ];

        $response = $this->createPayment($token, $payment_data);
        if (isset($response['bkashURL'])) {
            return '<form action="' . $response['bkashURL'] . '" method="GET">
                        <input type="submit" value="' . Language::_('Bkash.process.submit', true) . '" />
                    </form>';
        } else {
            $this->Input->setErrors(['payment' => ['error' => Language::_('Bkash.!error.payment_failed', true)]]);
            return false;
        }
    }

    public function validate(array $get, array $post) {
        $token = $this->grantToken();
        $payment_id = $post['paymentID'] ?? '';
        if (empty($payment_id)) {
            return ['status' => 'declined'];
        }

        $url = "https://checkout.sandbox.bka.sh/v1.2.0-beta/checkout/payment/execute/" . $payment_id;
        $headers = [
            "Authorization: Bearer $token",
            "X-APP-Key: " . $this->meta->app_key
        ];

        $response = $this->makeRequest($url, $headers, null);
        if (isset($response['transactionStatus']) && $response['transactionStatus'] === 'Completed') {
            return [
                'amount' => $response['amount'],
                'transaction_id' => $response['trxID'],
                'status' => 'approved'
            ];
        }
        return ['status' => 'declined'];
    }

    public function success(array $get, array $post) {
        return [
            'transaction_id' => $post['trxID'] ?? '',
            'status' => 'approved'
        ];
    }

    private function grantToken() {
        $url = "https://checkout.sandbox.bka.sh/v1.2.0-beta/token/grant";
        $headers = [
            "Content-Type: application/json",
            "username: " . $this->meta->username,
            "password: " . $this->meta->password
        ];
        $body = json_encode([
            'app_key' => $this->meta->app_key,
            'app_secret' => $this->meta->app_secret
        ]);

        $response = $this->makeRequest($url, $headers, $body);
        return $response['id_token'] ?? null;
    }

    private function createPayment($token, $data) {
        $url = "https://checkout.sandbox.bka.sh/v1.2.0-beta/checkout/payment/create";
        $headers = [
            "Authorization: Bearer $token",
            "X-APP-Key: " . $this->meta->app_key,
            "Content-Type: application/json"
        ];
        $response = $this->makeRequest($url, $headers, json_encode($data));
        return $response;
    }

    private function makeRequest($url, $headers, $body = null) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($body) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code >= 200 && $http_code < 300) {
            return json_decode($response, true) ?? [];
        }
        return [];
    }
}