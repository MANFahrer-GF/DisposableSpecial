<?php

namespace Modules\DisposableSpecial\Http\Controllers;

use App\Contracts\Controller;
use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Bid;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\Enums\FlightType;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\SimBrief;
use App\Models\Subfleet;
use App\Models\User;
use App\Services\AirportService;
use App\Services\FinanceService;
use App\Services\UserService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class DS_FreeFlightController extends Controller
{
   public function index()
   {
      // DisposableSpecial settings (einmalig lesen)
      $ds_freeflights_main = (bool) DS_Setting('dspecial.freeflights_main', false);
      if ($ds_freeflights_main === false) {
         flash()->error('Web based Free Flights are disabled... Please select a flight from schedule');
         return redirect('/flights');
      }

      $ds_req_balance_amount = (int) DS_Setting('dspecial.freeflights_reqbalance', 0);
      $ds_cost_per_edit_amount = (int) DS_Setting('dspecial.freeflights_costperedit', 0);
      $ds_company_fleet = (bool) DS_Setting('dspecial.freeflights_companyfleet', false);

      $ff_finance = false;
      $ff_balance = null;
      $ff_cost = null;

      if ($ds_req_balance_amount > 0) {
         $ff_finance = true;
         $ff_balance = Money::createFromAmount($ds_req_balance_amount);
         $ff_cost = Money::createFromAmount($ds_cost_per_edit_amount);
      }

      // App settings (einmalig lesen)
      $settings = [
         'ac_rank'        => setting('pireps.restrict_aircraft_to_rank', true),
         'ac_rating'      => setting('pireps.restrict_aircraft_to_typerating', false),
         'bid_block'      => setting('bids.block_aircraft', false),
         'sb_block'       => setting('simbrief.block_aircraft', false),
         'sb_callsign'    => setting('simbrief.callsign', false),
         'pilot_company'  => setting('pilots.restrict_to_company', false),
         'pilot_location' => setting('pilots.only_flights_from_current', false),
         'airline_fleet'  => $ds_company_fleet,
      ];

      $units = ['fuel' => setting('units.fuel')];

      // User laden
      $userId = Auth::id();
      $eager_user = ['airline', 'last_pirep', 'rank', 'journal'];

      /** @var \App\Models\User|null $user */
      $user = User::with($eager_user)->find($userId);

      if (!$user) {
         flash()->error('User not found.');
         return redirect('/flights');
      }

      // Finance check
      if ($ff_finance && $user->journal && $user->journal->balance < $ff_balance) {
         flash()->error('Not enough balance to perform a free flight. '.$ff_balance.' is required to proceed! Please select a flight from schedule...');
         return redirect('/flights');
      }

      $user_loc = $user->curr_airport_id ?? $user->home_airport_id;

      // Airline-Filter
      $al_where = ['active' => 1];

      // Allowed Subfleets berechnen
      $allowed_sf = [];

      if ($settings['ac_rank'] || $settings['ac_rating']) {
         $userSvc = app(UserService::class);
         $restricted_to = $userSvc->getAllowableSubfleets($user);
         $allowed_sf = $restricted_to->pluck('id')->toArray();
      }

      if ($settings['pilot_company']) {
         $al_where['id'] = $user->airline_id;

         $airline_sf = Subfleet::where('airline_id', $user->airline_id)->pluck('id')->toArray();
         $allowed_sf = filled($allowed_sf) ? array_values(array_intersect($allowed_sf, $airline_sf)) : $airline_sf;
      }

      // Airlines laden
      $airlines = Airline::where($al_where)->orderBy('name')->get();

      // Subfleets in EINER Query laden (statt N+1 im Loop)
      $fleet_list = [];
      if ($airlines->isNotEmpty()) {
         $subfleetIdsByAirline = Subfleet::query()
            ->whereIn('airline_id', $airlines->pluck('id'))
            ->whereNull('deleted_at')
            ->get(['id', 'airline_id'])
            ->groupBy('airline_id')
            ->map(fn($rows) => $rows->pluck('id')->values()->all());

         foreach ($airlines as $airline) {
            $fleet_list[$airline->id] = $subfleetIdsByAirline[$airline->id] ?? [];
         }
      }

      // ICAO Liste für JS
      $icao_list = [];
      foreach ($airlines as $airline) {
         $icao_list[$airline->id] = $airline->icao;
      }

      // Aircraft Query
      $ac_where = [
         'state'  => AircraftState::PARKED,
         'status' => AircraftStatus::ACTIVE,
      ];

      if ($user_loc && $settings['pilot_location']) {
         $ac_where['airport_id'] = $user_loc;
      }

      $withCount = [
         'bid',
         'simbriefs' => function ($query) { $query->whereNull('pirep_id'); },
      ];

      $aircraft = Aircraft::withCount($withCount)
         ->with('airline')
         ->where($ac_where)
         ->when(
            $settings['ac_rank'] || $settings['ac_rating'] || $settings['pilot_company'],
            function ($query) use ($allowed_sf) {
               // Falls allowed_sf leer ist, liefert whereIn([], ...) sonst gar nichts
               // Das ist meist korrekt (keine erlaubten Subfleets => keine Aircraft).
               return $query->whereIn('subfleet_id', $allowed_sf);
            }
         )
         ->when($settings['sb_block'], function ($query) {
            return $query->having('simbriefs_count', 0);
         })
         ->when($settings['bid_block'], function ($query) {
            return $query->having('bid_count', 0);
         })
         ->orderBy('icao')
         ->orderBy('registration')
         ->get();

      // Select2: Gesamtliste
      $select2data = [];
      $select2data[] = ['id' => 0, 'text' => __('DSpecial::common.selectac')];

      foreach ($aircraft as $ac) {
         $text = $ac->airline->icao.' | '.$ac->ident;

         if ($ac->registration != $ac->name) {
            $text .= ' '.$ac->name;
         }

         if ($ac->fuel_onboard[$units['fuel']] > 0) {
            $text .= ' | '.__('DSpecial::common.fuelob').': '.DS_ConvertWeight($ac->fuel_onboard, $units['fuel']);
         }

         $select2data[] = ['id' => $ac->id, 'text' => $text];
      }

      // Select2: Airline -> Aircraft (optional)
      $airline_fleet = null;

      if ($settings['airline_fleet']) {
         $aircraftBySubfleet = $aircraft->groupBy('subfleet_id');
         $airline_fleet = [];

         foreach ($airlines as $airline) {
            $airline_fleet[$airline->icao] = [];
            $airline_fleet[$airline->icao][] = ['id' => 0, 'text' => __('DSpecial::common.selectac')];

            $subfleetIds = $fleet_list[$airline->id] ?? [];
            foreach ($subfleetIds as $subfleetId) {
               foreach (($aircraftBySubfleet[$subfleetId] ?? collect()) as $ac) {
                  $text = $ac->airline->icao.' | '.$ac->ident;

                  if ($ac->registration != $ac->name) {
                     $text .= ' '.$ac->name;
                  }

                  if ($ac->fuel_onboard[$units['fuel']] > 0) {
                     $text .= ' | '.__('DSpecial::common.fuelob').': '.DS_ConvertWeight($ac->fuel_onboard, $units['fuel']);
                  }

                  $airline_fleet[$airline->icao][] = ['id' => $ac->id, 'text' => $text];
               }
            }
         }
      }

      // Personal Flight — GSG-Fix: pro Freiflug ein EIGENER Flight-Datensatz
      // (eigene flight_id). PaxStudio & Co. ordnen OFP/PIREP/Flugzeug über die
      // flight_id zu. Bisher wurde EIN PF-Satz pro Pilot wiederverwendet und
      // überschrieben → alle Freiflüge teilten sich dieselbe flight_id → OFPs
      // und PIREPs wurden vertauscht. Lösung: wir nehmen einen noch NICHT
      // geflogenen Freiflug-Entwurf (PF, ohne PIREP) als editierbare Vorlage;
      // sobald der letzte Freiflug geflogen ist (hat einen PIREP), entsteht
      // beim nächsten Mal automatisch ein NEUER Datensatz mit eigener flight_id.
      // PF-Entwürfe dieses Piloten (kleine Menge). Den PIREP-Check NUR über
      // diese wenigen IDs laufen lassen (statt alle PIREPs des Piloten zu
      // plucken) → skaliert auch bei Vielfliegern. (Flight hat keine
      // pireps()-Relation, daher manueller Lookup.)
      $pfFlights = Flight::where('user_id', $user->id)
         ->where('route_code', 'PF')
         ->orderByDesc('created_at')
         ->get();

      $flownIds = $pfFlights->isEmpty()
         ? collect()
         : Pirep::whereIn('flight_id', $pfFlights->pluck('id'))->pluck('flight_id');

      // Entwürfe, die schon eine (un-geflogene) OFP haben, sind ebenfalls
      // „belegt": die OFP hängt an der flight_id. Würden wir sie überschreiben,
      // zeigte die OFP plötzlich einen anderen Flug. → eigene flight_id schon
      // ab OFP-Erstellung, nicht erst nach dem Fliegen. (SimBrief.flight_id wird
      // beim PIREP-Filen genullt, daher trifft das nur aktive OFPs.)
      $ofpIds = $pfFlights->isEmpty()
         ? collect()
         : SimBrief::whereIn('flight_id', $pfFlights->pluck('id'))->pluck('flight_id');

      // Erster Entwurf OHNE PIREP und OHNE OFP = editierbare Vorlage.
      $fflight = $pfFlights->first(
         fn ($f) => !$flownIds->contains($f->id) && !$ofpIds->contains($f->id)
      );

      if (!$fflight) {
         $fflight = Flight::create([
            'airline_id'     => $user->airline_id,
            'flight_number'  => $user->id,
            'flight_type'    => 'E',
            'route_code'     => 'PF',
            'user_id'        => $user->id,
            'notes'          => $user->ident.' - '.$user->name_private,
            'dpt_airport_id' => $user_loc ?? 'ZZZZ',
            'arr_airport_id' => $user->home_airport_id ?? 'ZZZZ',
            'level'          => null,
            'distance'       => null,
            'route'          => null,
            'days'           => null,
            'active'         => 0,
            'visible'        => 0,
         ]);
      }

      return view('DSpecial::freeflights.index', [
         'aircraft'     => $aircraft,
         'airlines'     => $airlines,
         'icao'         => json_encode($icao_list),
         'ff_balance'   => $ff_balance,
         'ff_cost'      => $ff_cost,
         'fflight'      => $fflight,
         'fleet_full'   => json_encode($select2data),
         'fleet_comp'   => $airline_fleet,
         'flight_types' => FlightType::select(true),
         'settings'     => $settings,
         'units'        => ['fuel' => $units['fuel']],
         'user'         => $user,
      ]);
   }

   public function store(Request $request)
   {
      // Mandatory fields check (minimal-invasiv, wie original)
      if (strlen(trim((string) $request->ff_orig)) !== 4 || strlen(trim((string) $request->ff_dest)) !== 4) {
         flash()->error('Check Airport Inputs !');
         return redirect(route('DSpecial.freeflight'));
      }

      if (strlen(trim((string) $request->ff_number)) === 0) {
         flash()->error('Check Flight Number !');
         return redirect(route('DSpecial.freeflight'));
      }

      // Finance setting
      $ds_cost_per_edit_amount = (int) DS_Setting('dspecial.freeflights_costperedit', 0);
      $ff_finance = false;
      $ff_cost = null;

      if ($ds_cost_per_edit_amount > 0) {
         $ff_finance = true;
         $ff_cost = Money::createFromAmount($ds_cost_per_edit_amount);
      }

      // Airports lookup
      $airportSvc = app(AirportService::class);
      $orig = $airportSvc->lookupAirportIfNotFound(trim((string) $request->ff_orig));
      $dest = $airportSvc->lookupAirportIfNotFound(trim((string) $request->ff_dest));

      if (!$orig || !$dest) {
         flash()->error('Airport NOT found !!! Check ICAO codes and try again... Free Flight NOT Saved');
         return redirect(route('DSpecial.freeflight'));
      }

      // Update personal flight
      $dist = DS_CalculateDistance($orig->icao, $dest->icao);

      // GSG-Fix: Den gewählten Entwurf nur überschreiben, solange er noch NICHT
      // belegt ist. Hat er bereits einen PIREP (geflogen) ODER eine aktive OFP,
      // würde Überschreiben den Vor-/OFP-Flug verfälschen → stattdessen einen
      // NEUEN Flight-Datensatz anlegen (eigene flight_id schon ab OFP, nicht
      // erst nach dem Fliegen — so kann man zwei Freiflüge hintereinander mit
      // verschiedenem Flugzeug bauen, ohne dazwischen zu fliegen).
      $freeflight = Flight::where('id', $request->ff_id)->first();
      if ($freeflight && (
         Pirep::where('flight_id', $freeflight->id)->exists()
         || SimBrief::where('flight_id', $freeflight->id)->exists()
      )) {
         $freeflight = null;
      }
      if (!$freeflight) {
         $freeflight = new Flight();
         $freeflight->user_id = $request->user_id;
      }

      $freeflight->airline_id = $request->ff_airlineid;
      $freeflight->flight_number = trim((string) $request->ff_number);
      $freeflight->callsign = !empty($request->ff_callsign) ? trim((string) $request->ff_callsign) : null;
      $freeflight->route_code = 'PF';
      $freeflight->dpt_airport_id = $orig->icao;
      $freeflight->arr_airport_id = $dest->icao;
      $freeflight->distance = $dist;
      $freeflight->flight_time = DS_CalculateBlockTime($dist);
      $freeflight->days = null;
      $freeflight->route = null;
      $freeflight->flight_type = !empty($request->ff_iatatype) ? trim((string) $request->ff_iatatype) : 'E';
      $freeflight->notes = !empty($request->ff_owner) ? (string) $request->ff_owner : null;
      $freeflight->user_id = $request->user_id;
      $freeflight->active = 0;
      $freeflight->visible = 0;

      // Adjust load factor & variance by flight type
      if (in_array($freeflight->flight_type, ['I', 'K', 'P', 'T'], true)) {
         $freeflight->load_factor = 0;
         $freeflight->load_factor_variance = 0;
      } else {
         $freeflight->load_factor = null;
         $freeflight->load_factor_variance = null;
      }

      $freeflight->save();

      // Bid gegen den (ggf. neu angelegten) Freiflug — eigene flight_id
      Bid::updateOrCreate(
         [
            'user_id'   => $request->user_id,
            'flight_id' => $freeflight->id,
         ],
         [
            'aircraft_id' => !empty($request->ff_aircraft) ? $request->ff_aircraft : null,
         ]
      );

      if ($ff_finance) {
         $user = User::with('airline', 'journal')->find(Auth::id());
         if ($user && $user->journal) {
            $memo = 'FreeFlight '.$freeflight->dpt_airport_id.'-'.$freeflight->arr_airport_id.' '.Carbon::now()->format('ymdHi');
            $this->ChargeForFreeFlight($user, $ff_cost, $memo);
            flash()->success('Transaction Completed... Personal Flight Updated & Bid Inserted');
         } else {
            flash()->warning('Personal Flight Updated & Bid Inserted (User/Journal missing for charge)');
         }
      } else {
         flash()->success('Personal Flight Updated & Bid Inserted');
      }

      // SimBrief redirect — WICHTIG: die OFP muss an die flight_id des tatsächlich
      // gespeicherten Freiflugs gehen ($freeflight->id), NICHT an $request->ff_id
      // aus dem Formular. Falls store() oben einen NEUEN Datensatz angelegt hat
      // (alter Entwurf hatte schon PIREP/OFP), wäre die ID sonst verschieden →
      // Bid an neuer ID, OFP an alter ID = vertauscht. Genau das vermeiden wir.
      if (!empty(setting('simbrief.api_key'))) {
         $sblink = '?flight_id='.$freeflight->id;
         if ((string) $request->ff_aircraft !== '0') {
            $sblink .= '&aircraft_id='.$request->ff_aircraft;
         }

         return redirect(route('frontend.simbrief.generate').$sblink);
      }

      return redirect(route('frontend.flights.bids'));
   }

   public function ChargeForFreeFlight($user, $amount, $memo)
   {
      $financeSvc = app(FinanceService::class);

      // Charge User
      $financeSvc->debitFromJournal(
         $user->journal,
         $amount,
         $user,
         $memo,
         'FreeFlight Fees',
         'freeflight',
         Carbon::now()->format('Y-m-d')
      );

      // Credit Airline
      $financeSvc->creditToJournal(
         $user->airline->journal,
         $amount,
         $user,
         $memo.' UserID:'.$user->id,
         'FreeFlight Fees',
         'freeflight',
         Carbon::now()->format('Y-m-d')
      );

      Log::debug('Disposable Special | UserID:'.$user->id.' Name:'.$user->name_private.' charged for FreeFlight '.$memo);
   }
}