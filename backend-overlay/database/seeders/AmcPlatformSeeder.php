<?php

namespace Database\Seeders;

use App\Models\{AmcContract,AmcService,Asset,AssetType,Customer,Location,SlaPolicy,TicketCategory,TicketPriority,TicketStatus,User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AmcPlatformSeeder extends Seeder
{
    public function run(): void
    {
        $sla = SlaPolicy::updateOrCreate(['name' => 'Standard AMC'], [
            'critical_response_minutes' => 15, 'critical_resolution_minutes' => 240,
            'high_response_minutes' => 30, 'high_resolution_minutes' => 480,
            'medium_response_minutes' => 120, 'medium_resolution_minutes' => 1440,
            'low_response_minutes' => 240, 'low_resolution_minutes' => 2880,
            'is_active' => true,
        ]);

        foreach ([
            ['Critical','critical',4], ['High','high',3], ['Medium','medium',2], ['Low','low',1],
        ] as [$name,$slug,$level]) {
            TicketPriority::updateOrCreate(['slug'=>$slug], compact('name','slug','level'));
        }

        foreach ([
            ['New','new',10,false], ['Assigned','assigned',20,false], ['In Progress','in_progress',30,false],
            ['Waiting Customer','waiting_customer',40,false], ['Waiting Vendor','waiting_vendor',50,false],
            ['Waiting Parts','waiting_parts',60,false], ['Resolved','resolved',70,true],
            ['Customer Confirmation','customer_confirmation',80,false], ['Closed','closed',90,true], ['Cancelled','cancelled',100,true],
        ] as [$name,$slug,$sort_order,$is_closed]) {
            TicketStatus::updateOrCreate(['slug'=>$slug], compact('name','slug','sort_order','is_closed'));
        }

        foreach (['Network','Internet','LAN','VLAN','Wi-Fi','VPN','Windows Server','Linux','VMware','Backup','Outlook','Exchange','OneDrive','SharePoint','Teams','FortiGate','Security Policy','License','Desktop','Laptop','Printer','Server Hardware','CCTV','Other'] as $name) {
            TicketCategory::updateOrCreate(['slug'=>Str::slug($name,'_')], ['name'=>$name,'slug'=>Str::slug($name,'_')]);
        }

        foreach (['Firewall','Switch','Access Point','Server','Desktop','Laptop','Printer','UPS','NAS','CCTV Camera','NVR/DVR','PBX','Other'] as $name) {
            AssetType::updateOrCreate(['name'=>$name], ['is_active'=>true]);
        }

        foreach (['Network Support','Server Support','Microsoft 365','Firewall Management','Backup','Preventive Maintenance'] as $name) {
            AmcService::updateOrCreate(['name'=>$name], ['is_active'=>true]);
        }

        // Business fixtures and demo users are strictly opt-in and never seeded in production.
        if (! app()->environment(['local', 'testing']) || ! env('AMC_SEED_DEMO_USERS', false)) return;

        $customer = Customer::updateOrCreate(['customer_code'=>'AMC-0001'], [
            'company_name'=>'Demo Customer LLC','email'=>'support@demo-customer.test','city'=>'Dubai','country'=>'UAE','status'=>'active'
        ]);

        $location = Location::updateOrCreate(['customer_id'=>$customer->id,'name'=>'Head Office'], [
            'city'=>'Dubai','status'=>'active'
        ]);

        $contract = AmcContract::updateOrCreate(['contract_number'=>'AMC-2026-0001'], [
            'customer_id'=>$customer->id,'sla_policy_id'=>$sla->id,'start_date'=>now()->startOfYear()->toDateString(),
            'end_date'=>now()->endOfYear()->toDateString(),'contract_type'=>'Standard AMC','currency'=>'AED','status'=>'active'
        ]);
        $contract->services()->sync(AmcService::pluck('id'));

        $firewallType = AssetType::where('name','Firewall')->firstOrFail();
        Asset::updateOrCreate(['asset_tag'=>'FW-DEMO-001'], [
            'customer_id'=>$customer->id,'location_id'=>$location->id,'asset_type_id'=>$firewallType->id,
            'name'=>'Main Firewall','manufacturer'=>'Fortinet','model'=>'FortiGate','status'=>'active'
        ]);

        // The demo-user credentials below share the opt-in guard above.

        $password = Hash::make('ChangeMe123!');
        User::updateOrCreate(['email'=>'admin@example.com'], ['name'=>'Platform Admin','password'=>$password,'role'=>'super_admin','status'=>'active','customer_id'=>null]);
        User::updateOrCreate(['email'=>'manager@example.com'], ['name'=>'Operations Admin','password'=>$password,'role'=>'admin','status'=>'active','customer_id'=>null]);
        User::updateOrCreate(['email'=>'technician@example.com'], ['name'=>'Demo Technician','password'=>$password,'role'=>'technician','status'=>'active','customer_id'=>null]);
        User::updateOrCreate(['email'=>'customer@example.com'], ['name'=>'Customer Admin','password'=>$password,'role'=>'customer_admin','status'=>'active','customer_id'=>$customer->id]);
    }
}
