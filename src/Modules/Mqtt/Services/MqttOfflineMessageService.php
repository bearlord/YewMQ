<?php

namespace App\Modules\Mqtt\Services;


use App\Models\Extension\MqttOfflineMessage;

class MqttOfflineMessageService
{
    /**
     * @param array $data
     * @return bool
     */
    public function saveOfflineMessage(array $data): bool
    {
        $model = new MqttOfflineMessage();
        $model->setAttributes($data, false);
        $model->save(false);

        return true;
    }

    /**
     * @param string $clientId
     * @return bool
     */
    public function deleteOfflineMessageByClientId(string $clientId): bool
    {
        MqttOfflineMessage::deleteAll([
            'client_id' => $clientId
        ]);

        return true;

    }

}