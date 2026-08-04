<?php

namespace App\Services;

use App\Models\RequestForm;

class RequestFormService
{
    public function cancelRequest(RequestForm $request_form)
    {
        return $request_form->update([
            'status' => "Canceled"
        ]);
    }
}