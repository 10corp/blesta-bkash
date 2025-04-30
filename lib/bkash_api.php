<?php
class BkashApi {
    public function getToken($meta, $url) {
        $headers = [
            "Content-Type: application/json",
            "username: " . $meta["username"],
            "password: " . $meta["password"]
        ];
        $body = json_encode([
            "app_key" => $meta["app_key"],
            "app_secret" => $meta["app_secret"]
        ]);

        return $this->makeRequest($url, $headers, $body);
    }

    public function createPayment($data, $token, $url) {
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token,
            "X-APP-Key: " . $data["app_key"]
        ];
        $body = json_encode($data);

        return $this->makeRequest($url, $headers, $body);
    }

    public function executePayment($payment_id, $token, $url) {
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token
        ];

        return $this->makeRequest($url . "/" . $payment_id, $headers);
    }

    public function refundPayment($data, $token, $url) {
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer " . $token,
            "X-APP-Key: " . $data["app_key"]
        ];
        $body = json_encode($data);

        return $this->makeRequest($url, $headers, $body);
    }

    private function makeRequest($url, $headers, $body = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Enable in production
        if ($body) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("cURL Error: " . $error);
        }

        return json_decode($response, true);
    }
}
?>