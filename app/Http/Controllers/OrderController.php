<?php

namespace App\Http\Controllers;

use App\Models\Color;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ReceiptService;

class OrderController extends Controller
{
    protected $receiptService;
    
    public function __construct(ReceiptService $receiptService)
    {
        $this->receiptService = $receiptService;
    }
    
    public function create(Request $request)
    {
        /* DB::beginTransaction();

        try { */
            $saledPersonId = $request->salesPersonId;
            $branchId = User::find($saledPersonId)->branch_id ?? null;
            $totalSaleItems = count($request->input('orderItems', []));
            $totalReturnItems = count($request->input('returnItems', []));

            $orderItems = $request->input('orderItems', []);
            $returnItems = $request->input('returnItems', []);

            $totalAmount = collect($orderItems)->sum(function ($item) {
                return $item['price'] * 1;
            }) + collect($returnItems)->sum(function ($item) {
                return $item['price'] * 1;
            });

            $totalPayableAmount = 0;
            $totalOrderPayableAmount = collect($orderItems)->sum(function ($item) {
                $price = isset($item['changedPrice']['amount']) ? $item['changedPrice']['amount'] : $item['price'];
                return $price * 1;
            });

            $totalReturnAmount = collect($returnItems)->sum(function ($item) {
                $price = isset($item['changedPrice']['amount']) ? $item['changedPrice']['amount'] : $item['price'];
                return $price * 1;
            });
            $totalPayableAmount = $totalOrderPayableAmount - $totalReturnAmount;

            $totalPaidAmount = array_sum(array_column($request->paymentInfo, 'amount'));

            $order = Order::create([
                'sales_person_id' => $saledPersonId,
                'branch_id' => $branchId,
                'total_items' => $totalSaleItems + $totalReturnItems,
                'total_return_items' => $totalReturnItems,
                'total_payable_amount' => $totalPayableAmount,
                'total_amount' => $totalAmount,
                'paid_amount' => $totalPaidAmount,
                'source' => 'POS'
            ]);

            foreach ($orderItems as $orderItem) {
                $params = [
                    'order_id'=> $order->id,
                    'flag' => 'SALE',
                    'sales_person_id' => $saledPersonId,
                    'branch_id' => $branchId
                ];

                $this->createOrderItem($orderItem, $params);
            }

            foreach ($returnItems as $returnItem) {
                $params = [
                    'order_id'=> $order->id,
                    'flag' => 'RETURN',
                    'sales_person_id' => $saledPersonId,
                    'branch_id' => $branchId
                ];

                $this->createOrderItem($returnItem, $params);
            }

            $paymentMethods = $request->input('paymentInfo', []);
            $exchangeRate = setting("euro_to_pound");

            foreach($paymentMethods as $paymentMethod){
                if($paymentMethod['method'] === 'Euro'){
                    $convertedAmount = (float) $paymentMethod['amount'] * $exchangeRate;
                }
                
                OrderPayment::create([
                    'order_id' => $order->id,
                    'method' => $paymentMethod['method'],
                    'amount' => $paymentMethod['amount'],
                    'original_amount' => $convertedAmount ?? $paymentMethod['amount']
                ]);
            }
            
            try {
                $this->receiptService->printOrderReceipt($order->id);
                 return response()->json([
                    'success' => true,
                    'message' => 'Order created successfully!',
                    'order' => $order
                ], 201);
             } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to print order receipt!',
                ], 200);
            }
            
            
        /* } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage()
            ], 500);
        } */
    }

    protected function createOrderItem($orderItem, $params)
    {
        $productId = $orderItem['product_id'];
        $product = Product::with('brand', 'supplier')->find($productId);
        $colorDetail = Color::find($orderItem['color_id']);

        OrderItem::create([
            'order_id'  => $params['order_id'],
            'product_id' => $product->id,
            'size_id' => $orderItem['size_id'],
            'size' => $orderItem['size'],
            'color_id' => $orderItem['color_id'],
            'color_name' => $orderItem['color'],
            'color_code' => $product->id,
            'ui_color_code' => $colorDetail->ui_color_code,
            'brand_id' => $product->brand_id,
            'brand_name' => $product->brand->name,
            'supplier_id' => $product->supplier->id ?? null,
            'supplier_short_code' => $product->supplier->short_code ?? null,
            'supplier_name' => $product->supplier->supplier_name ?? null,
            'article_code' => $product->article_code,
            'barcode' => $orderItem['barcode'],
            'original_price' => $product->mrp,
            'changed_price' => isset($orderItem['changedPrice']['amount']) ? $orderItem['changedPrice']['amount'] : $product->mrp,
            'changed_price_reason_id' => isset($orderItem['changedPrice']['reasonId']) ? $orderItem['changedPrice']['reasonId'] : null,
            'changed_price_reason' => isset($orderItem['changedPrice']['reason']) ? $orderItem['changedPrice']['reason'] : '',
            'quantity' => 1,
            'description' => '',
            'flag' => $params['flag'],
            'sales_person_id' => $params['sales_person_id'],
            'branch_id' => $params['branch_id'],
        ]);
    }
}