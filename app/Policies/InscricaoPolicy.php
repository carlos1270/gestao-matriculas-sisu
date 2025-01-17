<?php

namespace App\Policies;

use App\Models\Inscricao;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class InscricaoPolicy
{
    use HandlesAuthorization;

    /**
     * Create a new policy instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function isCandidatoDono(User $user, Inscricao $inscricao)
    {
        $userPolicy = new UserPolicy();
        if ($userPolicy->isAdminOrAnalista($user)) {
            return true;
        }elseif($userPolicy->isCandidato($user) && $inscricao->candidato->user->id == $user->id){
            return true;
        }else{
            return false;
        }
    }
}
