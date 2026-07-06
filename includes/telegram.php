<?php

class Telegram {
    private $token;
    private $apiUrl;

    public function __construct($token) {
        $this->token = $token;
        $this->apiUrl = "https://api.telegram.org/bot{$token}/";
    }

    public function callApi($method, $params = []) {
        $ch = curl_init($this->apiUrl . $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POST, true);
            $hasFile = false;
            foreach ($params as $v) {
                if ($v instanceof CURLFile) { $hasFile = true; break; }
            }
            if ($hasFile) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            } else {
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            }
        }

        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }

    public function sendMessage($chatId, $text, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->callApi('sendMessage', $params);
    }

    public function sendInlineButtons($chatId, $text, $buttons) {
        return $this->sendMessage($chatId, $text, [
            'inline_keyboard' => $buttons,
        ]);
    }

    public function answerCallbackQuery($callbackQueryId, $text = null) {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text) $params['text'] = $text;
        return $this->callApi('answerCallbackQuery', $params);
    }

    public function editMessageText($chatId, $messageId, $text, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->callApi('editMessageText', $params);
    }

    public function getFile($fileId) {
        return $this->callApi('getFile', ['file_id' => $fileId]);
    }

    public function downloadFile($filePath) {
        $url = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

    public function editMessageReplyMarkup($chatId, $messageId, $replyMarkup = null) {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup ?: ['inline_keyboard' => []],
        ];
        return $this->callApi('editMessageReplyMarkup', $params);
    }

    public function deleteMessage($chatId, $messageId) {
        return $this->callApi('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function setWebhook($url) {
        return $this->callApi('setWebhook', [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query', 'chat_member'],
        ]);
    }

    public function deleteWebhook() {
        return $this->callApi('deleteWebhook');
    }
}
