<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListagemRequest;
use App\Models\Listagem;
use Illuminate\Http\Request;
use App\Models\Inscricao;
use App\Models\Cota;
use App\Models\Curso;
use Barryvdh\DomPDF\Facade as PDF;
use App\Models\Chamada;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Bus;
use App\Jobs\EnviarEmailsPublicacaoListagem;

class ListagemController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(ListagemRequest $request)
    {
        set_time_limit(300);
        $this->authorize('isAdmin', User::class);
        $request->validated();
        $listagem = new Listagem();
        $listagem->setAtributes($request);
        $listagem->caminho_listagem = 'caminho';
        $listagem->save();

        switch ($request->tipo) {
            case Listagem::TIPO_ENUM['convocacao']:
                $listagem->caminho_listagem = $this->gerarListagemConvocacao($request, $listagem);
                break;
            case Listagem::TIPO_ENUM['pendencia']:
                $listagem->caminho_listagem = $this->gerarListagemPendencia($request, $listagem);
                break;
            case Listagem::TIPO_ENUM['resultado']:
                $listagem->caminho_listagem = $this->gerarListagemResultado($request, $listagem);
                break;
            case Listagem::TIPO_ENUM['final']:
                $listagem->caminho_listagem = $this->gerarListagemFinal($request, $listagem);
                break;
        }
        $listagem->update();

        return redirect()->back()->with(['success_listagem' => 'Listagem criada com sucesso']);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Listagem  $listagem
     * @return \Illuminate\Http\Response
     */
    public function show(Listagem $listagem)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Listagem  $listagem
     * @return \Illuminate\Http\Response
     */
    public function edit(Listagem $listagem)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Listagem  $listagem
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Listagem $listagem)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Listagem  $listagem
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $this->authorize('isAdmin', User::class);
        $listagem = Listagem::find($id);

        if (Storage::disk()->exists('public/' .$listagem->caminho_listagem)) {
            Storage::delete('public/'.$listagem->caminho_listagem);
        }

        $listagem->delete();

        return redirect()->back()->with(['success_listagem' => 'Listagem deletada com sucesso.']);
    }

    /**
     * Gera o arquivo pdf da listagem de convocacao e retorna o caminho do arquivo.
     *
     * @param  \App\Http\Requests\ListagemRequest  $request
     * @return string $caminho_do_arquivo
     */
    private function gerarListagemConvocacao(ListagemRequest $request, Listagem $listagem)
    {
        $chamada = Chamada::find($request->chamada);
        $cursos = Curso::whereIn('id', $request->cursos)->orderBy('nome')->get();
        $cotas = Cota::whereIn('id', $request->cotas)->orderBy('nome')->get();
        $inscricoes = collect();
        $ordenacao = $this->get_ordenacao($request);
        $ordem = $this->get_ordem($request);

        foreach ($cursos as $i => $curso) {
            $inscricoes_curso = collect();
            if($curso->turno == Curso::TURNO_ENUM['matutino']){
                $turno = 'Matutino';
            }elseif($curso->turno == Curso::TURNO_ENUM['vespertino']){
                $turno = 'Vespertino';
            }elseif($curso->turno == Curso::TURNO_ENUM['noturno']){
                $turno = 'Noturno';
            }elseif($curso->turno == Curso::TURNO_ENUM['integral']){
                $turno = 'Integral';
            }
            $ampla = collect();
            foreach ($cotas as $j => $cota) {
                //Juntar todos aqueles que são da ampla concorrencia independente do bonus de 10%
                if($cota->getCodCota() == Cota::COD_COTA_ENUM['A0']){
                    $ampla2 = Inscricao::select('inscricaos.*')
                        ->where([['co_curso_inscricao', $curso->cod_curso], ['chamada_id', $chamada->id], ['ds_turno', $turno]])
                        ->whereIn(
                            'no_modalidade_concorrencia',
                            [
                                'Ampla concorrência',
                                'que tenham cursado integralmente o ensino médio em qualquer uma das escolas situadas nas microrregiões do Agreste ou do Sertão de Pernambuco.',
                                'AMPLA CONCORRÊNCIA'
                            ]
                        )
                        ->join('candidatos','inscricaos.candidato_id','=','candidatos.id')
                        ->join('users','users.id','=','candidatos.user_id')
                        ->orderBy($ordenacao, $ordem)
                        ->get();
                    $ampla = $ampla->concat($ampla2);
                }else if($cota->getCodCota() == Cota::COD_COTA_ENUM['B4342']){
                    //ignorar a de 10% visto que entra na mesma tabela que A0
                }else{
                    $inscritosCota = Inscricao::select('inscricaos.*')->
                    where([['co_curso_inscricao', $curso->cod_curso], ['no_modalidade_concorrencia', $cota->getCodCota()], ['chamada_id', $chamada->id], ['ds_turno', $turno]])
                        ->join('candidatos','inscricaos.candidato_id','=','candidatos.id')
                        ->join('users','users.id','=','candidatos.user_id')
                        ->orderBy($ordenacao, $ordem)
                        ->get();
                    if($inscritosCota->count() > 0 ){
                        $inscricoes_curso->push($inscritosCota);
                    }
                }
            }
            if($ampla->count() > 0){
                $inscricoes_curso->prepend($ampla);
            }
            if ($inscricoes_curso->count() > 0) {
                $inscricoes->push($inscricoes_curso);
            }
        }
        $pdf = PDF::loadView('listagem.inscricoes', ['collect_inscricoes' => $inscricoes, 'chamada' => $chamada]);

        return $this->salvarListagem($listagem, $pdf->stream());
    }

    /**
     * Salva o arquivo de listagem em seu diretorio.
     *
     * @param  \App\Models\Listagem  $listagem
     * @param  string $arquivo
     * @return string $caminho_do_arquivo
     */
    private function salvarListagem(Listagem $listagem, $arquivo)
    {
        $path = 'listagem/' . $listagem->id . '/';
        $nome = 'listagem.pdf';
        Storage::put('public/' . $path . $nome, $arquivo);
        return $path . $nome;
    }

    /**
     * Gera o arquivo pdf da listagem de resultado e retorna o caminho do arquivo.
     *
     * @param  \App\Http\Requests\ListagemRequest  $request
     * @return string $caminho_do_arquivo
     */
    private function gerarListagemResultado(ListagemRequest $request, Listagem $listagem)
    {
        $chamada = Chamada::find($request->chamada);
        $cursos = Curso::whereIn('id', $request->cursos)->orderBy('nome')->get();
        $cotas = Cota::whereIn('id', $request->cotas)->orderBy('nome')->get();
        $inscricoes = collect();
        $ordenacao = $this->get_ordenacao($request);
        $ordem = $this->get_ordem($request);

        foreach ($cursos as $i => $curso) {
            $inscricoes_curso = collect();
            if($curso->turno == Curso::TURNO_ENUM['matutino']){
                $turno = 'Matutino';
            }elseif($curso->turno == Curso::TURNO_ENUM['vespertino']){
                $turno = 'Vespertino';
            }elseif($curso->turno == Curso::TURNO_ENUM['noturno']){
                $turno = 'Noturno';
            }elseif($curso->turno == Curso::TURNO_ENUM['integral']){
                $turno = 'Integral';
            }
            $ampla = collect();
            foreach ($cotas as $j => $cota) {
                //Juntar todos aqueles que são da ampla concorrencia independente do bonus de 10%
                if($cota->getCodCota() == Cota::COD_COTA_ENUM['A0']){
                    $ampla2 = Inscricao::select('inscricaos.*')
                        ->where([['co_curso_inscricao', $curso->cod_curso], ['chamada_id', $chamada->id], ['ds_turno', $turno]])
                        ->whereIn(
                            'no_modalidade_concorrencia',
                            [
                                'Ampla concorrência',
                                'que tenham cursado integralmente o ensino médio em qualquer uma das escolas situadas nas microrregiões do Agreste ou do Sertão de Pernambuco.',
                                'AMPLA CONCORRÊNCIA'
                            ]
                        )
                        ->join('candidatos','inscricaos.candidato_id','=','candidatos.id')
                        ->join('users','users.id','=','candidatos.user_id')
                        ->orderBy($ordenacao, $ordem)
                        ->get();
                    $ampla = $ampla->concat($ampla2);
                }else if($cota->getCodCota() == Cota::COD_COTA_ENUM['B4342']){
                    //ignorar a de 10% visto que entra na mesma tabela que A0
                }else{
                    $inscritosCota = Inscricao::select('inscricaos.*')->
                    where([['co_curso_inscricao', $curso->cod_curso], ['no_modalidade_concorrencia', $cota->getCodCota()], ['chamada_id', $chamada->id], ['ds_turno', $turno]])
                        ->join('candidatos','inscricaos.candidato_id','=','candidatos.id')
                        ->join('users','users.id','=','candidatos.user_id')
                        ->orderBy($ordenacao, $ordem)
                        ->get();
                    if($inscritosCota->count() > 0 ){
                        $inscricoes_curso->push($inscritosCota);
                    }
                }
            }
            if($ampla->count() > 0){
                $inscricoes_curso->prepend($ampla);
            }
            if ($inscricoes_curso->count() > 0) {
                $inscricoes->push($inscricoes_curso);
            }
        }
        $pdf = PDF::loadView('listagem.resultado', ['collect_inscricoes' => $inscricoes, 'chamada' => $chamada]);

        return $this->salvarListagem($listagem, $pdf->stream());
    }

    private function gerarListagemFinal(ListagemRequest $request, Listagem $listagem)
    {
        $chamada = Chamada::find($request->chamada);
        $sisu = $chamada->sisu;
        $cursos = Curso::all();
        $cotas = Cota::all();
        $candidatosIngressantesCursos = collect();
        $candidatosReservaCursos = collect();

        foreach($cursos as $curso){
            $candidatosIngressantesCurso = collect();

            foreach($cotas as $cota){
                $candidatosCotaCurso = Inscricao::where([['sisu_id', $sisu->id], ['curso_id', $curso->id],
                ['cota_id', $cota->id], ['cd_efetivado', Inscricao::STATUS_VALIDACAO_CANDIDATO['cadastro_validado']]])->get();

                $candidatosCotaCurso = $candidatosCotaCurso->sortByDesc(function($candidato){
                    return $candidato['nu_nota_candidato'];
                });

                $cota_curso_quantidade = $curso->cotas()->where('cota_id', $cota->id)->first()->pivot->quantidade_vagas;

                foreach($candidatosCotaCurso as $candidato){
                    if($cota_curso_quantidade > 0){
                        if(!$candidatosIngressantesCurso->contains($candidato)){
                            $candidatosIngressantesCurso->push($candidato);
                            $cota_curso_quantidade -= 1;
                        }
                    }
                }

                if($cota_curso_quantidade > 0){
                    foreach($cota->remanejamentos as $remanejamento){
                        $cotaRemanejamento = $remanejamento->proximaCota;
                        $candidatosCotaCursoRemanejamento = Inscricao::where([['sisu_id', $sisu->id], ['curso_id', $curso->id],
                        ['cota_id', $cotaRemanejamento->id], ['cd_efetivado', Inscricao::STATUS_VALIDACAO_CANDIDATO['cadastro_validado']]])->get();

                        $candidatosCotaCursoRemanejamento = $candidatosCotaCursoRemanejamento->sortByDesc(function($candidato){
                            return $candidato['nu_nota_candidato'];
                        });

                        $continua = false;

                        foreach($candidatosCotaCursoRemanejamento as $candidato){
                            if($cota_curso_quantidade > 0){
                                if(!$candidatosIngressantesCurso->contains($candidato)){
                                    $candidatosIngressantesCurso->push($candidato);
                                    $cota_curso_quantidade -= 1;
                                }
                            }else{
                                $continua = true;
                                break;
                            }
                        }
                        if($continua){
                            break;
                        }
                        
                    }
                }
            }
            if($candidatosIngressantesCurso->count() > 40){
                $primeiroSemestre = collect();
                $segundoSemestre = collect();

                $cotasL9L13 = Cota::whereIn('cod_cota', ['L9', 'L13'])->get();
                $retorno = $this->divirPorSemestre($cotasL9L13, $candidatosIngressantesCurso, $primeiroSemestre, $segundoSemestre, true);
                $primeiroSemestre = $retorno[0];
                $segundoSemestre = $retorno[1];

                $cotasL10L14 = Cota::whereIn('cod_cota', ['L10', 'L14'])->get();
                $retorno = $this->divirPorSemestre($cotasL10L14, $candidatosIngressantesCurso, $primeiroSemestre, $segundoSemestre, true);
                $primeiroSemestre = $retorno[0];
                $segundoSemestre = $retorno[1];

                $cotasNaoDeficientes = Cota::whereIn('cod_cota', ['A0', 'L1', 'L2', 'L5', 'L6'])->get();
                $retorno = $this->divirPorSemestre($cotasNaoDeficientes, $candidatosIngressantesCurso, $primeiroSemestre, $segundoSemestre, false);
                $primeiroSemestre = $retorno[0];
                $segundoSemestre = $retorno[1];
                

                if($request->ordenacao == "nome"){
                    $primeiroSemestre = $primeiroSemestre->sortBy(function($candidato){
                        return $candidato->candidato->user->name;
                    });
                    $segundoSemestre = $segundoSemestre->sortBy(function($candidato){
                        return $candidato->candidato->user->name;
                    });
                }else{
                    $primeiroSemestre = $primeiroSemestre->sortByDesc(function($candidato){
                        return $candidato['nu_nota_candidato'];
                    });
                    $segundoSemestre = $segundoSemestre->sortByDesc(function($candidato){
                        return $candidato['nu_nota_candidato'];
                    });
                }
                $candidatosIngressantesCursos->push($primeiroSemestre);
                $candidatosIngressantesCursos->push($segundoSemestre);
            }

            if($request->ordenacao == "nome"){
                $candidatosIngressantesCurso = $candidatosIngressantesCurso->sortBy(function($candidato){
                    return $candidato->candidato->user->name;
                });
            }else{
                $candidatosIngressantesCurso = $candidatosIngressantesCurso->sortByDesc(function($candidato){
                    return $candidato['nu_nota_candidato'];
                });
            }
        
            $candidatosIngressantesCursos->push($candidatosIngressantesCurso);
            $candidatosCurso = Inscricao::where([['sisu_id', $sisu->id], ['curso_id', $curso->id],
            ['cd_efetivado', Inscricao::STATUS_VALIDACAO_CANDIDATO['cadastro_validado']]])->get();

            $candidatosReservaCurso = $candidatosCurso->diff($candidatosIngressantesCurso);
            if($request->ordenacao == "nome"){
                $candidatosReservaCurso = $candidatosReservaCurso->sortBy(function($candidato){
                    return $candidato->candidato->user->name;
                });
            }else{
                $candidatosReservaCurso = $candidatosReservaCurso->sortByDesc(function($candidato){
                    return $candidato['nu_nota_candidato'];
                });
            }
            $candidatosReservaCursos->push($candidatosReservaCurso);
        }

        $pdf = PDF::loadView('listagem.final', ['candidatosIngressantesCursos' => $candidatosIngressantesCursos, 'candidatosReservaCursos' => $candidatosReservaCursos,'chamada' => $chamada]);

        return $this->salvarListagem($listagem, $pdf->stream());
    }

    private function divirPorSemestre($cotas, $candidatosIngressantesCurso, $primeiroSemestre, $segundoSemestre, $deficiente)
    {
        foreach($cotas as $cota){
            $porCota = $candidatosIngressantesCurso->where('cota_id', $cota->id)->sortByDesc(function($candidato){
                return $candidato['nu_nota_candidato'];
            });
            if($deficiente){
                if($cotas->first()->cod_cota == 'L9'){
                    $primeiroSemestre = $primeiroSemestre->concat($porCota);
                    $second = collect();
                    $segundoSemestre = $segundoSemestre->concat($second);
                }elseif($cotas->first()->cod_cota == 'L10'){
                    $first = collect();
                    $primeiroSemestre = $primeiroSemestre->concat($first);
                    $segundoSemestre = $segundoSemestre->concat($porCota);
                }
            }else{
                $metade = ceil($porCota->count()/2);
                $divisoes = $porCota->chunk($metade);
    
                if($divisoes->count()>0){
                    $first = $divisoes[0];
                }else{
                    $first = collect();
                }
                if($divisoes->count()>1){
                    $second = $divisoes[1];
                }else{
                    $second = collect();
                }
    
                if($first->count()!=$second->count()){
                    if($primeiroSemestre->count()<$segundoSemestre->count()){
                        $primeiroSemestre = $primeiroSemestre->concat($first);
                        $segundoSemestre = $segundoSemestre->concat($second);
                    }elseif($primeiroSemestre->count()>$segundoSemestre->count()){
                        $ultimoElemento = $first->slice($first->count()-1, 1)->first();
                        $first = $first->slice(0, -1);
                        $second->push($ultimoElemento);
    
                        $primeiroSemestre = $primeiroSemestre->concat($first);
                        $segundoSemestre = $segundoSemestre->concat($second);
                    }else{
                        $primeiroSemestre = $primeiroSemestre->concat($first);
                        $segundoSemestre = $segundoSemestre->concat($second);
                    }
                }else{
                    $primeiroSemestre = $primeiroSemestre->concat($first);
                    $segundoSemestre = $segundoSemestre->concat($second);
                }
            }
        }
        return array($primeiroSemestre, $segundoSemestre);
    }

    /**
     * Pega a string de ordenação garantindo que a coluna certa de ordenação irá ser passada.
     *
     * @param  \App\Http\Requests\Request  $request
     * @return string $coluna
     */
    private function get_ordenacao(Request $request)
    {
        $coluna = 'name';
        switch ($request->ordenacao) {
            case 'nome':
                $coluna = 'name';
                break;
            case 'nota':
                $coluna = 'inscricaos.nu_nota_candidato';
                break;
        }
        return $coluna;
    }

    /**
     * Pega a string de ordem da coluna : ASC ou DESC.
     *
     * @param  \App\Http\Requests\Request  $request
     * @return string $ordem
     */
    private function get_ordem(Request $request)
    {
        $ordem = 'ASC';
        switch ($request->ordenacao) {
            case 'nome':
                $ordem = 'ASC';
                break;
            case 'nota':
                $ordem = 'DESC';
                break;
        }
        return $ordem;
    }

    /**
     * Gera o arquivo pdf da listagem de pendencia e retorna o caminho do arquivo.
     *
     * @param  \App\Http\Requests\ListagemRequest  $request
     * @return string $caminho_do_arquivo
     */
    private function gerarListagemPendencia(ListagemRequest $request, Listagem $listagem)
    {
        $chamada = Chamada::find($request->chamada);
        $cursos = Curso::whereIn('id', $request->cursos)->orderBy('nome')->get();
        $cotas = Cota::whereIn('id', $request->cotas)->orderBy('nome')->get();
        $ordenacao = $this->get_ordenacao($request);
        $ordem = $this->get_ordem($request);

        $inscricoes = collect();

        foreach ($cursos as $i => $curso) {
            $inscricoes_curso = collect();
            if($curso->turno == Curso::TURNO_ENUM['matutino']){
                $turno = 'Matutino';
            }elseif($curso->turno == Curso::TURNO_ENUM['vespertino']){
                $turno = 'Vespertino';
            }elseif($curso->turno == Curso::TURNO_ENUM['noturno']){
                $turno = 'Noturno';
            }elseif($curso->turno == Curso::TURNO_ENUM['integral']){
                $turno = 'Integral';
            }
            $ampla = collect();
            foreach ($cotas as $j => $cota) {
                //Juntar todos aqueles que são da ampla concorrencia independente do bonus de 10%
                if($cota->getCodCota() == Cota::COD_COTA_ENUM['A0']){
                    $ampla2 = Inscricao::select('inscricaos.*')
                        ->where([['co_curso_inscricao', $curso->cod_curso], ['chamada_id', $chamada->id], ['ds_turno', $turno]])
                        ->whereIn(
                            'no_modalidade_concorrencia',
                            [
                                'Ampla concorrência',
                                'que tenham cursado integralmente o ensino médio em qualquer uma das escolas situadas nas microrregiões do Agreste ou do Sertão de Pernambuco.',
                                'AMPLA CONCORRÊNCIA'
                            ]
                        )
                        ->join('candidatos','inscricaos.candidato_id','=','candidatos.id')
                        ->join('users','users.id','=','candidatos.user_id')
                        ->orderBy($ordenacao, $ordem)
                        ->get();
                    $ampla = $ampla->concat($ampla2);
                }else if($cota->getCodCota() == Cota::COD_COTA_ENUM['B4342']){
                    //ignorar a de 10% visto que entra na mesma tabela que A0
                }else{
                    $inscritosCota = Inscricao::select('inscricaos.*')->
                    where([['co_curso_inscricao', $curso->cod_curso], ['no_modalidade_concorrencia', $cota->getCodCota()], ['chamada_id', $chamada->id], ['ds_turno', $turno]])
                        ->join('candidatos','inscricaos.candidato_id','=','candidatos.id')
                        ->join('users','users.id','=','candidatos.user_id')
                        ->orderBy($ordenacao, $ordem)
                        ->get();
                    if($inscritosCota->count() > 0 ){
                        $inscricoes_curso->push($inscritosCota);
                    }
                }
            }
            if($ampla->count() > 0){
                $inscricoes_curso->prepend($ampla);
            }
            if ($inscricoes_curso->count() > 0) {
                $inscricoes->push($inscricoes_curso);
            }
        }

        $pdf = PDF::loadView('listagem.pendencia', ['collect_inscricoes' => $inscricoes, 'chamada' => $chamada]);

        return $this->salvarListagem($listagem, $pdf->stream());
    }

    public function publicar(Request $request) {
        $listagem = Listagem::find($request->listagem_id);
        $listagem->publicada = $request->publicar;

        if ($listagem->job_batch_id == null && $listagem->enviaEmails()) {
            $batch = Bus::batch([
                new EnviarEmailsPublicacaoListagem($listagem),
            ])->name('Enviar e-mails da listagem id: '.$listagem->id)->dispatch();
            $listagem->job_batch_id = $batch->id;
        }

        return $listagem->save();
    }
}
