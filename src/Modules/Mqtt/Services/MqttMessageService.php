<?php

namespace App\Modules\Mqtt\Services;


use App\Models\Extension\MqttMessage;

class MqttMessageService
{

    /**
     * @param array $data
     * @return bool
     */
    public function saveMessage(array $data): bool
    {
        $model = new MqttMessage();

        $model->setAttributes($data, false);
        $model->save(false);

        return true;
    }
}