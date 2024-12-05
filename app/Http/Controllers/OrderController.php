<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use App\Models\Medicine;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\OrderExport;




class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function exportExcel() {
        return Excel::download(new OrderExport, 'rekap-pembelian.xlsx');
    }
    

     public function data () {
        $orders = Order::with('user')
        ->when(request('date'), function ($query) {
            $query->whereDate('created_at', request('date'));
        })
        ->simplePaginate(5);
        return view('order.admin.index', compact('orders'));    
     }
    public function index()
    {
        
        $orders = Order::with('user')
        ->where('user_id', Auth::user()->id)
        ->when(request('date'), function ($query) {
            $query->whereDate('created_at', request('date'));
        })
        ->simplePaginate(5);

        return view('order.kasir.kasir', compact('orders'));
        
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $medicines = Medicine::all();
        return view('order.kasir.create', compact('medicines'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        
        // Validasi data yang dikirimkan dalam request
        $request->validate([
            'validateDate' => '',
            'name_customer' => 'required',
            'medicines' => 'required',
        ]);

        // Cari jumlah item yang sama pada array 'medicines'
        // Hasilnya akan berupa array dengan struktur:
        // [ "item" => "jumlah" ]
        $arrayDistinct = array_count_values($request->medicines);

        // Siapkan array kosong untuk menampung hasil akhir
        $arrayAssocMedicines = [];

        // Looping hasil penghitungan item yang sama (duplikat)
        foreach ($arrayDistinct as $id => $count) {
            // Cari data obat berdasarkan ID
            $medicine = Medicine::where('id', $id)->first();
        
            // Hitung sub total harga (harga obat * jumlah)
            $subPrice = $medicine['price'] * $count;

            // pengecekan ketersediaan stok obat
            if ($medicine['stock'] < $count) {
                $valueFormBefore = [
                    'name_costumer' => $request->name_customer,
                    'medicines' => $request->medicines
                ];
                $msg = 'Stok obat '. $medicine['name'] . ' tidak mencukupi!' . $medicine['stock'];


                return redirect()->back()->with([
                    'failed' => $msg,
                    'valueFormBefore' => $valueFormBefore
                ]);
            }
        
            // Buat struktur data untuk setiap obat
            $arrayItem = [
                "id" => $id,
                "name_medicine" => $medicine['name'],
                "qty" => $count,
                "price" => $medicine['price'],
                "sub_price" => $subPrice,
            ];
        
            // Tambahkan data obat ke array
            $arrayAssocMedicines[] = $arrayItem;
        }
        
        // masukkan struktur array tersebut ke array kosong yg disediakan sebelumnya
        // array_push($arrayAssocMedicines, $arrayItem);

        // total   
        //  harga pembelian dari obat-obat yg dipilih
        $totalPrice = 0;

        // looping format array medicines baru
        foreach ($arrayAssocMedicines as $item) {
            // total harga pembelian ditambahkan dr keseluruhan sub price data medicines   

            $totalPrice += (int)$item['sub_price'];
        }

        // harga beli ditambah 10% ppn
        $totalPriceWithPPN = $totalPrice + ($totalPrice * 0.01);

        // tambah data ke database
        $newOrder = Order::create([
            'user_id' => Auth::user()->id,
            'medicines' => $arrayAssocMedicines,
            'name_customer' => $request->name_customer,
            'total_price' => $totalPriceWithPPN,
        ]);

        // update stok obat
        foreach ($arrayAssocMedicines as $key => $item) {
            $stockBefore = Medicine::where('id', $item['id'])->value('stock');
            Medicine::where('id', $item['id'])->update([
                "stock" => $stockBefore - $item['qty']
            ]);

        }

        

        if($newOrder) {
            return redirect()->route('kasir.order.print',   $newOrder['id'])->with('success', 'Order berhasil');
        } else {
            return redirect()->back()->with('error', 'order gagal');
        }
       

        
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $order = Order::find($id);
        return view('order.kasir.print', compact('order'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        //
    }

    public function downloadPDF($id) {
        // ambil data yg akan ditampilkan pada pdf, bisa juga dengan where atau eloquent lainnya dan jangan gunakan pagination
        $order = Order::find($id)->toArray();
        // kirim data yg diambil kepada view yg akan ditampilkan, kirim dengan inisial
        view()->share('order',$order);
        // panggil view blade yg akan dicetak pdf serta data yg akan digunakan
        $pdf = Pdf::loadView('order.kasir.cetak-pdf', $order);
        // download PDF file dengan nama tertentu
        return $pdf->download('receipt.pdf');
  }

  

}
