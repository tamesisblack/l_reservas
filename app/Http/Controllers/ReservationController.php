<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Reservation;
use App\Models\ReservationDetail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Log;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

use Carbon\Carbon;

use Twilio\Rest\Client;

class ReservationController extends Controller{

    //Método para mostrar todas las reservas
    public function index(){
        // Obtener todas las reservas con sus relaciones de usuario y consultor
        $reservations = Reservation::with(['user', 'consultant'])->get();
        return view('reservations.index', compact('reservations'));
    }

    // Método para mostrar las reservas del cliente autenticado
    public function indexcliente() {
        $userId = Auth::user()->id; // Obtener el ID del usuario autenticado
        $reservations = Reservation::where('user_id', $userId)->get(); // Obtener solo las reservas del usuario
        return view('cliente.index', compact('reservations'));
    }

    // Método para mostrar la vista de creación de una nueva reserva
    public function create() {
        // Obtener los usuarios con rol de cliente (rol_id = 3)
        $users = User::where('rol_id', 3)->whereNull('deleted_at')->get();
        // Obtener los consultores (rol_id = 2)
        $consultants = User::where('rol_id', 2)->whereNull('deleted_at')->get();
        return view('reservations.create', compact('users', 'consultants'));
    }

    // Método para mostrar la vista de creación de una reserva desde el lado del cliente
    public function createCliente() {
        $consultants = User::where('rol_id', 2)->whereNull('deleted_at')->get();
        return view('cliente.reserva', compact('consultants'));
    }

    // Método para almacenar una nueva reserva
    public function store(Request $request) {
        // Validación de los datos recibidos
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'consultant_id' => 'required|exists:users,id',
            'reservation_date' => 'required|date',
            'start_time' => 'required|date_format:H:i|after_or_equal:09:00|before_or_equal:15:00',
            'end_time' => 'required|date_format:H:i|before_or_equal:15:00',
            'reservation_status' => 'required|in:pendiente,confirmada,cancelada',
            'payment_status' => 'required|in:pendiente,pagado,fallido',
            'total_amount' => 'required|numeric|min:0',
        ]);

        // Creación de la reserva
        $reservation = Reservation::create([
            'user_id' => $request->user_id,
            'consultant_id' => $request->consultant_id,
            'reservation_date' => $request->reservation_date,
            'start_time' => $request->start_time,
            'end_time' => $request->end_time,
            'reservation_status' => $request->reservation_status,
            'payment_status' => $request->payment_status,
            'total_amount' => $request->total_amount,
        ]);

        // Envío de correo de confirmación
        $this->sendConfirmationEmail($reservation);

        // Envío de mensaje de WhatsApp si el usuario tiene teléfono
        $user = User::find($request->user_id);
        $userPhone = $user->teléfono;
        if ($userPhone) {
            $this->sendWhastsAppMessage($userPhone, $this->generateWhatsAppMessage($reservation, $user));
        }

        return redirect()->route('reservations.index')->with('success', 'Reserva creada correctamente');
    }

    // Método para mostrar el formulario de edición de una reserva
    public function edit(string $id) {
        // Encontrar la reserva por su ID
        $reservation = Reservation::findOrFail($id);
        $reservation->start_time = Carbon::parse($reservation->start_time)->format('H:i');
        $reservation->end_time = Carbon::parse($reservation->end_time)->format('H:i');

        $users = User::where('rol_id', 3)->whereNull('deleted_at')->get();
        $consultants = User::where('rol_id', 2)->whereNull('deleted_at')->get();

        return view('reservations.edit', compact('reservation', 'users', 'consultants'));
    }

    // Método para actualizar una reserva existente
    public function update(Request $request, string $id) {
        // Validación de los datos recibidos
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'consultant_id' => 'required|exists:users,id',
            'reservation_date' => 'required|date',
            'start_time' => 'required|date_format:H:i|after_or_equal:09:00|before_or_equal:15:00',
            'end_time' => 'required|date_format:H:i|before_or_equal:15:00',
            'reservation_status' => 'required|in:pendiente,confirmada,cancelada',
            'payment_status' => 'required|in:pendiente,pagado,fallido',
            'total_amount' => 'required|numeric|min:0',
        ]);

        $reservation = Reservation::findOrFail($id);
        $reservation->update($request->all());

        return redirect()->route('reservations.index')->with('success', 'Reserva actualizada correctamente');
    }

    // Método para cancelar una reserva
    public function cancel(Request $request) {
        // Validación de los datos
        $request->validate([
            'reservation_id' => 'required|exists:reservations,id',
            'cancellation_reason' => 'required|string',
        ]);

        $reservation = Reservation::findOrFail($request->reservation_id);
        $reservation->reservation_status = 'cancelada'; // Cambia el estado a 'cancelada'
        $reservation->cancellation_reason = $request->cancellation_reason;
        $reservation->save();

        return response()->json([
            'success' => true,
            'message' => 'La reserva ha sido cancelada exitosamente',
        ]);
    }

    // Métodos para obtener todas las reservas y convertirlas en eventos de calendario (para consultores y clientes)
    public function getAllReservations(){
        $reservations = Reservation::all();
        $events = [];
        foreach($reservations as $reservation){
            $color = '#28a745';
            $bordercolor = '#28a745';

            if($reservation->reservation_status === 'pendiente'){
                $color = '#ffc107';
                $bordercolor = '#ffc107';
            }elseif($reservation->reservation_status === 'cancelada'){
                $color = '#dc3545';
                $bordercolor = '#dc3545';
            }

            $events[] = [
                'title' => 'Reserva de '. $reservation->user->nombres .' '. $reservation->user->apellidos .' con ' . $reservation->consultant->nombres .' '. $reservation->consultant->apellidos,
                'start' => $reservation->reservation_date.'T'.$reservation->start_time,
                'end' => $reservation->reservation_date.'T'.$reservation->end_time,
                'backgroundColor' => $color,
                'borderColor' => $bordercolor,
            ];
        }

        return response()->json($events);
    }

    public function getAllReservationsLanding(){
        $reservations = Reservation::all();
        $events = [];
        foreach($reservations as $reservation){
            $color = '#28a745';
            $bordercolor = '#28a745';

            if($reservation->reservation_status === 'pendiente'){
                $color = '#ffc107';
                $bordercolor = '#ffc107';
            }elseif($reservation->reservation_status === 'cancelada'){
                $color = '#dc3545';
                $bordercolor = '#dc3545';
            }

            $events[] = [
                'title' => $reservation->consultant->nombres .' '. $reservation->consultant->apellidos,
                'start' => $reservation->reservation_date.'T'.$reservation->start_time,
                'end' => $reservation->reservation_date.'T'.$reservation->end_time,
                'backgroundColor' => $color,
                'borderColor' => $bordercolor,
            ];
        }

        return response()->json($events);
    }

    public function getReservationsAsesor(){

        $consultantId = Auth::user()->id;

        $reservations = Reservation::where('consultant_id',$consultantId)->get();

        $events = [];
        foreach($reservations as $reservation){
            $color = '#28a745';
            $bordercolor = '#28a745';

            if($reservation->reservation_status === 'pendiente'){
                $color = '#ffc107';
                $bordercolor = '#ffc107';
            }elseif($reservation->reservation_status === 'cancelada'){
                $color = '#dc3545';
                $bordercolor = '#dc3545';
            }

            $events[] = [
                'title' => 'Reserva con '. $reservation->user->nombres .' '. $reservation->user->apellidos,
                'start' => $reservation->reservation_date.'T'.$reservation->start_time,
                'end' => $reservation->reservation_date.'T'.$reservation->end_time,
                'backgroundColor' => $color,
                'borderColor' => $bordercolor,
            ];
        }

        return response()->json($events);
    }

    public function getReservationsCliente(){

        $userId = Auth::user()->id;

        $reservations = Reservation::where('user_id',$userId)->get();

        $events = [];
        foreach($reservations as $reservation){
            $color = '#28a745';
            $bordercolor = '#28a745';

            if($reservation->reservation_status === 'pendiente'){
                $color = '#ffc107';
                $bordercolor = '#ffc107';
            }elseif($reservation->reservation_status === 'cancelada'){
                $color = '#dc3545';
                $bordercolor = '#dc3545';
            }

            $events[] = [
                'title' => 'Reserva con '. $reservation->consultant->nombres .' '. $reservation->consultant->apellidos,
                'start' => $reservation->reservation_date.'T'.$reservation->start_time,
                'end' => $reservation->reservation_date.'T'.$reservation->end_time,
                'backgroundColor' => $color,
                'borderColor' => $bordercolor,
            ];
        }

        return response()->json($events);
    }

    // Método para completar el pago de la reserva y crear el registro de la reserva y detalles del pago
    public function completePayment(Request $request){

        $request->validate([
            'orderID' =>'required',
            'details' => 'required',
            'user_id' => 'required|exists:users,id',
            'consultant_id' => 'required|exists:users,id',
            'reservation_date' => 'required|date',
            'start_time' => 'required|date_format:H:i|after_or_equal:09:00|before_or_equal:15:00',
            'end_time' => 'required|date_format:H:i|before_or_equal:15:00',
            'total_amount' => 'required|numeric|min:0',
        ]);

        $details =$request->details;
        $payment_status = $details['status'];

        if($payment_status === 'COMPLETED'){

            $reservation = Reservation::create([
                'user_id' => $request -> user_id,
                'consultant_id' => $request -> consultant_id,
                'reservation_date' => $request -> reservation_date,
                'start_time' => $request -> start_time,
                'end_time' => $request -> end_time,
                'reservation_status' => 'confirmada',
                'payment_status' => 'pagado',
                'total_amount' => $request -> total_amount,
            ]);

            $transaction_id = $details['id'] ?? null;
            $payer_id = $details['payer']['payer_id'] ?? null;
            $payer_email = $details['payer']['email_address'] ?? null;
            $amount = $details['purchase_units'][0]['amount']['value'] ?? null;

            ReservationDetail::create([
                'reservation_id' => $reservation->id,
                'transaction_id' => $transaction_id,
                'payer_id' =>  $payer_id,
                'payer_email' => $payer_email,
                'payment_status' => $payment_status,
                'amount' => $amount,
                'response_json' => json_encode($details),
            ]);

            $this->sendConfirmationEmail($reservation);

            $user = User::find($request->user_id);
            $userPhone = $user->teléfono;
            if($userPhone){
                $this->sendWhastsAppMessage($userPhone, $this->generateWhatsAppMessage($reservation,$user));
            }

            return response()->json(['success' => true]);
        } else {
            return response()->json(['error' => 'Pago no completado'], 400);
        }
    }

    // Método para enviar el correo de confirmación de la reserva
    public function sendConfirmationEmail($reservation){
        $user = User::find($reservation->user_id);
        $consultant = User::find($reservation->consultant_id);

        $mail = new PHPMailer(true);

        try{
            $mail->isSMTP();
            $mail->Host = 'smtp.hostinger.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'andercode@anderson-bastidas.com';
            $mail->Password = 'Laravelv1@';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;

            $mail->setFrom('andercode@anderson-bastidas.com','AnderCode Reservas');
            $mail->addAddress($user->email);

            $mail->CharSet = 'UTF-8';

            $mail->Subject = 'Confirmacion de Reserva - AnderCode';

            $html = View::make('emails.reserva',[
                'userName' => $user->nombres .' '. $user->apellidos,
                'consultantName' => $consultant->nombres .' '. $consultant->apellidos,
                'reservationDate' => $reservation->reservation_date,
                'startTime' => $reservation->start_time,
                'endTime' => $reservation->end_time,
                'totalAmount' => $reservation->total_amount,
            ])->render();

            $mail->isHTML(true);
            $mail->Body = $html;

            $mail->send();

            return back()->with('success', 'Correo enviado correctamente.');

        } catch(Exception $e){
            Log::error('Error al enviar el correo: '. $mail->ErrorInfo);
            return back()->with('error','Error al enviar el correo :' . $mail->ErrorInfo);
        }
    }

    // Método para generar el mensaje de confirmación de WhatsApp
    protected function generateWhatsAppMessage($reservation, $user){
        return "Hola {$user->nombres}"." "."{$user->apellidos}, tu reserva ha sido confirmada.\n".
            "Fecha: {$reservation->reservation_date}\n".
            "Hora de Inicio: {$reservation->start_time}\n".
            "Hora de Fin: {$reservation->end_time}\n".
            "Costo Total: {$reservation->total_amount}\n".
            "Gracias por elegir nuestros servicios.\n".
            "AnderCode.\n";
    }

    // Método para enviar un mensaje de WhatsApp
    protected function sendWhastsAppMessage($to,$message){
        $sid = env('TWILIO_SID');
        $token = env('TWILIO_AUTH_TOKEN');
        $twilio = new Client($sid,$token);

        $twilio->messages->create(
            "whatsapp:+{$to}",
            [
                'from' => env('TWILIO_WHATSAPP_FROM'),
                'body' => $message
            ]
        );
    }

    // Método para mostrar todos los pagos en el sistema
    public function showPayments(){
        $payments = ReservationDetail::with(['reservation.user','reservation.consultant'])->get();
        return view('reservations.pagos',compact('payments'));
    }

    // Método para mostrar los pagos del cliente autenticado
    public function showClientPayments(){
        $userId = Auth::id();

        $payments = ReservationDetail::whereHas('reservation',function($query) use ($userId){
            $query->where('user_id',$userId);
        })->get();
        return view('cliente.pagos',compact('payments'));
    }
}
