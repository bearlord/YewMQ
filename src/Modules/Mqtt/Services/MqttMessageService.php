<?php

namespace App\Modules\Mqtt\Services;


use App\Models\Extension\MqttMessage;

class MqttMessageService
{

    /**
     * @param array $data
     * @return MqttMessage
     */
    public function saveMessage(array $data): MqttMessage
    {
        $model = new MqttMessage();

        $model->setAttributes($data, false);
        $model->save(false);

        return $model;
    }
}