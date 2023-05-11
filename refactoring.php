<?php
/**
 * Created by PhpStorm.
 * User: kohone
 * Date: 22.11.2018
 * Time: 16:09
 */

namespace App\Services\Dashboard;


use App\Http\Resources\DealResource\DealDashboardResource;
use App\Http\Resources\LeadResource\LeadDashboardResource;
use App\Models\Deal\Deal;
use App\Models\Lead\Lead;
use App\Models\Payment\Payment;
use App\Models\Team\Team;
use App\Models\Team\TeamUser;
use App\Models\User;
use App\Services\Deal\DealService;
use App\Services\Lead\LeadService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DashboardService
{
    /**
     * @var Lead
     */
    private $_leadModel;

    /**
     * @var
     */
    private $_leadService;

    /**
     * @var
     */
    private $_dealService;


    /**
     * @var
     */
    private $_role;


    /**
     * @var mixed
     */
    private $_user;


    public function __construct(
        Lead $leadModel,
        LeadService $leadService,
        DealService $dealService
    ) {
        $this->_leadModel = $leadModel;
        $this->_leadService = $leadService;
        $this->_dealService = $dealService;
      }


    public function userDashboardData(Request $request)
    {
        $deals = [];
        $leads = [];
        $total_payments = '';
        $statuses_role = Lead::getLeadStatusForList();
        $list = $this->_leadModel;

        $this->_user = $request->user();
        $this->_role = $this->_user->getRole();

        $this->_leadService->useOffset($list);
        $this->_leadService->useSort($list);
        $this->_leadService->useFilter($list);

        switch ($this->_role) {
            case User::ROLE_TEAMLEAD:
                $team = Team::select('teams.*')
                    ->leftJoin('team_teamleads', 'team_teamleads.team_id', '=', 'teams.id')
                    ->where('team_teamleads.user_id', $this->_user->id)
                    ->first();

                if ($team) {
                    $total_payments = $this->getTeamPayments($team);
                    $teamUsers = TeamUser::where('team_id', $team->id)
                        ->groupBy('user_id')
                        ->pluck('user_id')
                        ->toArray();
                    $deals = Deal::whereIn('manager_id', $teamUsers)->orderBy('deals.created_at', 'desc')->take(5)->get(
                    );
                    $leads = $list->with([
                        'country',
                        'mirrors' => function ($mirror) {
                            $mirror->where('main', true);
                        }
                    ])->whereIn('status', $statuses_role[$this->_role])->whereIn('owner_id', $teamUsers)->orderBy(
                        'leads.created_at',
                        'desc'
                    )->take(5)->get();
                }

                break;

            case User::ROLE_FIN_CONTROL:
                $deals = Deal::whereHas('payments', function ($query) {
                    $query->where('status', Payment::STATUS_WAITING_FOR_PAYMENT);
                })->where([['fincontrol_id', $this->_user->id]])->orderBy('deals.created_at', 'desc')->take(5)->get();
                break;

            case User::ROLE_BUYING_CONTROL:
                $list = (new Deal())->newQuery();
                $list->select('deals.*');
                $list->where([['deals.status', Deal::STATUS_REVIEW]]);
                $this->_dealService->useFilter($list);
                $deals = $list->orderBy('deals.created_at', 'desc')->take(5)->get();
                break;

            case User::ROLE_AUDITOR:
                $deals = Deal::whereHas('payments', function ($query) {
                    $query->where('status', Payment::STATUS_AUDIT);
                })->orderBy('deals.created_at', 'desc')->take(5)->get();
                break;

            case User::ROLE_SEARCHER:
                $leads = Lead::with([
                    'country',
                    'mirrors' => function ($mirror) {
                        $mirror->where('main', true);
                    }
                ])->where('owner_id', $this->_user->id)->whereIn('status', $statuses_role[$this->_role])->orderBy(
                    'leads.created_at',
                    'desc'
                )->take(5)->get();
                break;

            case User::ROLE_CHIEF:
                $deals = Deal::where('type', Deal::DEAL_TYPE_OFFLINE)->orderBy('deals.created_at', 'desc')->take(
                    5
                )->get();
                break;

            case User::ROLE_CONTROL:
                $team_user = TeamUser::where('user_id', $this->_user->id)->first();
                $user_team = TeamUser::where('team_id', $team_user->team_id)->pluck('user_id')->toArray();
                $leads = $list->with([
                    'country',
                    'mirrors' => function ($mirror) {
                        $mirror->where('main', true);
                    }
                ])->whereIn('status', $statuses_role[$this->_role])->whereIn('owner_id', $user_team)->orderBy(
                    'leads.created_at',
                    'desc'
                )->take(5)->get();
                $deals = [];
                break;

            case User::ROLE_HEAD_CONTROL:
                $teams = Team::select('teams.*')
                    ->leftJoin('team_heads', 'team_heads.team_id', '=', 'teams.id')
                    ->where('team_heads.user_id', $this->_user->id)
                    ->get();

                if (count($teams)) {
                    $teamUsers[$this->_user->id] = $this->_user->id;

                    // собираем манагеров со всех тим, в которых состоит head
                    foreach ($teams as $team) {
                        $result = TeamUser::where('team_id', $team->id)
                            ->groupBy('user_id')
                            ->get()
                            ->pluck('user_id');

                        if (count($result)) {
                            foreach ($result as $item) {
                                $teamUsers[$item] = $item;
                            }
                        }
                    }

                    $deals = Deal::whereIn('manager_id', $teamUsers)->orderBy('deals.created_at', 'desc')->take(5)->get(
                    );
                }

                break;

            case User::ROLE_MANAGER:
                $leads = $list->with([
                    'country',
                    'mirrors' => function ($mirror) {
                        $mirror->where('main', true);
                    }
                ])->whereIn('status', $statuses_role[$this->_role])->groupBy('leads.id')->orderBy(
                    'leads.created_at',
                    'desc'
                )->take(5)->get();

                $deals = Deal::whereIn('status', $statuses_role[$this->_role])->where([['manager_id', $this->_user->id]]
                )->orderBy('deals.created_at', 'desc')->take(5)->get();

                break;

            case User::ROLE_ADMIN:
                $leads = $list->with([
                    'country',
                    'mirrors' => function ($mirror) {
                        $mirror->where('main', true);
                    }
                ])->whereIn('status', $statuses_role[$this->_role])->where(
                    'leads.created_at',
                    '<=',
                    Carbon::now()
                )->orderBy('leads.created_at', 'desc')->take(5)->get();

                $deals = Deal::where('type', '!=', Deal::DEAL_TYPE_GOOGLE_DOCS)->orderBy(
                    'deals.created_at',
                    'desc'
                )->take(5)->get();

                $total_payments = $this->getTeamPayments();

                break;
        }

        $response = [
            'deals' => DealDashboardResource::collection($deals),
            'leads' => LeadDashboardResource::collection($leads),
            'crm_payments' => isset($total_payments['cpm']) ? $total_payments['cpm'] : null,
            'doc_payments' => isset($total_payments['gdoc']) ? $total_payments['gdoc'] : null
        ];

        return $response;
    }


    public function getTeamPayments($team = null)
    {
        $startDate = Carbon::now()->startOfMonth();
        $endDate = Carbon::now();
        $costItem = 3;
        $google_doc_percent = 0;
        $crm_percent = 0;

        $list = Payment::select(
            'payments.created_at',
            'payments.usd_summary as usd_cost',
            'teams.name as team_name',
            'deals.type as deal_type'
        )
            ->leftJoin('deals', 'payments.deal_id', '=', 'deals.id')
            ->leftJoin('teams', 'deals.team_id', '=', 'teams.id')
            ->whereBetween('payments.created_at', [$startDate, $endDate])
            ->where('payments.status', Payment::STATUS_DONE)
            ->where('deals.cost_item_id', $costItem);

        if (!is_null($team)) {
            $list->where('deals.team_id', $team->id);
        }
        $payments = $list->groupBy('payments.id')->get();

        foreach ($payments as $payment) {
            if ($payment->deal_type === Deal::DEAL_TYPE_GOOGLE_DOCS) {
                $google_doc_percent++;
            }
            $crm_percent++;
        }
        if ($crm_percent > 0) {
            $google_doc_percent = (round((($google_doc_percent / $crm_percent) * 100), 2));
            $crm_percent = 100 - $google_doc_percent;
        }

        return ['cpm' => $crm_percent, 'gdoc' => $google_doc_percent];
    }

}
