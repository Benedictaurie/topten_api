<?php

namespace App\Http\Resources;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiResponseResource extends JsonResource
{
    //Mendeklarasi properti
    public $status;
    public $message;
    public $resource;
    public $success;

    //Membuat _construct (konstruktor)
    public function __construct($status=200, $message, $resource=null, $success){
        $this->status = $status;
        $this->message = $message;
        parent::__construct($resource);
        $this->success = $success;
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
