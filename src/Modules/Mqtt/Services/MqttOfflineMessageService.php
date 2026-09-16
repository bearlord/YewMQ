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

    /**
     * Return the buffered (undelivered) offline messages for a client, oldest first.
     *
     * @param string $clientId
     * @return MqttOfflineMessage[]
     */
    public function getUndeliveredByClientId(string $clientId): array
    {
        return MqttOfflineMessage::find()
            ->where(['client_id' => $clientId, 'delivered' => 0])
            ->orderBy(['id' => 'ASC'])
            ->all();
    }

}