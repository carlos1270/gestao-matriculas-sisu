<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Sisu;
use App\Models\DataChamada;
use App\Models\Listagem;
use App\Models\User;
use App\Notifications\ContatoNotification;
use Illuminate\Support\Facades\Notification;

class WelcomeController extends Controller
{
    public function index()
    {
        $intervalo_inicio = now()->subMonth(2);
        $intervalo_fim = now()->addMonth(2);
        
        $edicao_atual = Sisu::where([['created_at', '>=', $intervalo_inicio], ['created_at', '<=', $intervalo_fim]])->first();
        $chamadas = $edicao_atual->chamadas()->orderBy('created_at', 'DESC')->get();

        $checagem_chamada = $this->listas_a_serem_exibidas($chamadas);

        return view('welcome', compact('chamadas', 'edicao_atual', 'checagem_chamada'))->with(['tipos_data' => DataChamada::TIPO_ENUM, ['tipos_listagem' => Listagem::TIPO_ENUM]]);
    }

    public function login()
    {
        return view('auth.login');
    }

    public function sobre()
    {
        return view('about');
    }

    public function contato()
    {
        return view('contact');
    }

    public function enviarMensagem(Request $request)
    {
        $request->validate([
            'assunto' => 'required|string|min:3|max:255',
            'email'         => 'required|email',
            'mensagem'      => 'required|min:25|max:2000',
            'nome_completo' => 'required|string|min:7|max:150',
        ]);

        $user = User::where('role', User::ROLE_ENUM['admin'])->first();
        $user->email = env('MAIL_CONTATO');

        Notification::send($user, new ContatoNotification($request, $request->assunto));

        return redirect()->back()->with(['success' => 'Obrigado por entrar em contato, sua mensagem foi enviada com sucesso!']);
    }

    public function envio_docs() {
        return view('informacoes.enviar_docs');
    }

    private function listas_a_serem_exibidas($chamadas) {
        foreach ($chamadas as $chamada) {
            foreach ($chamada->listagem as $listagem) {
                if ($listagem->publicada) {
                    return false;
                }
            }
        }

        return true;
    }
}
