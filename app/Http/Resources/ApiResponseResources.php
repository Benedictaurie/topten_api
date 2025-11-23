<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiResponseResources extends JsonResource
{
    //Mendeklarasi properti
    public $success;
    public $message;
    public $resource;
    public $status;

    //Membuat _construct (konstruktor)
    public function __construct($success, $message, $resource=null, $status=200){
        $this->success = $success;
        $this->message = $message;
        parent::__construct($resource);
        $this->status = $status;
    }

     /**
     * mengubah sebuah resource menjadi sebuah array.
     *
     * @return array<string, mixed>
     */

    public function toArray(Request $request): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'data' => parent::toArray($request),
        ];
    }

    public function withResponse($request, $response)
    {
        $response->setStatusCode($this->status); //atur HTTP response dengan status code 
    }
}
