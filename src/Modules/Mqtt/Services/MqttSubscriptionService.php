<?php

namespace App\Modules\Mqtt\Services;

use App\Models\Extension\MqttSubscription;

class MqttSubscriptionService
{
    /**
     * @param array $data
     * @return bool
     */
    public function saveSubscription(array $data): bool
    {
        $model = new MqttSubscription();
        $model->setAttributes($data);
        $model->save(false);

        return true;
    }

    /**
     * @param $clientId
     * @return bool
     */
    public function deleteSubscriptionsByClientId($clientId): bool
    {
        MqttSubscription::deleteAll([
            'client_id' => $clientId
        ]);

        return true;

    }
}