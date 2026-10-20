<?php
namespace Tests\Feature;
use App\Models\{Asset, Customer, TicketCategory, TicketPriority, TicketStatus, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAssetAssignmentsTest extends TestCase {
    use RefreshDatabase;
    private function fixtures(): array {
        $a = Customer::create(['company_name'=>'Hospital','customer_code'=>'H']);
        $b = Customer::create(['company_name'=>'Other','customer_code'=>'O']);
        $category = TicketCategory::create(['name'=>'Devices','slug'=>'devices']);
        $assets = [];
        foreach ([$a,$a,$b] as $i => $customer) $assets[] = Asset::create(['customer_id'=>$customer->id,'category_id'=>$category->id,'name'=>'Device '.$i,'asset_tag'=>'D'.$i]);
        $user = User::factory()->create(['role'=>'customer_user','customer_id'=>$a->id,'status'=>'active']);
        $admin = User::factory()->create(['role'=>'admin','status'=>'active']);
        return [$a,$b,$category,$assets,$user,$admin];
    }
    public function test_admins_assign_shared_assets_and_remove_assignments_without_deleting_assets(): void {
        [$a,$b,$category,$assets,$user,$admin] = $this->fixtures();
        $this->actingAs($admin)->putJson('/api/v2/users/'.$user->id.'/assets',['asset_ids'=>[$assets[0]->id,$assets[1]->id]])->assertOk();
        $this->getJson('/api/v2/users/'.$user->id.'/assets')->assertJsonCount(2,'asset_ids');
        $ca = User::factory()->create(['role'=>'customer_admin','customer_id'=>$a->id,'status'=>'active']);
        $second = User::factory()->create(['role'=>'customer_user','customer_id'=>$a->id,'status'=>'active']);
        $this->actingAs($ca)->putJson('/api/v2/users/'.$second->id.'/assets',['asset_ids'=>[$assets[0]->id]])->assertOk();
        $this->putJson('/api/v2/users/'.$user->id.'/assets',['asset_ids'=>[$assets[2]->id]])->assertUnprocessable();
        $this->assertCount(2,$user->assignedAssets);
        $this->actingAs($admin)->patchJson('/api/v2/directory/assets/'.$assets[0]->id,['customer_id'=>$b->id])->assertUnprocessable();
        $this->actingAs($ca)->putJson('/api/v2/users/'.$user->id.'/assets',['asset_ids'=>[]])->assertOk();
        $this->assertDatabaseHas('assets',['id'=>$assets[0]->id]);
        $foreign = User::factory()->create(['role'=>'customer_user','customer_id'=>$b->id]);
        $this->putJson('/api/v2/users/'.$foreign->id.'/assets',['asset_ids'=>[]])->assertNotFound();
        foreach (['customer_user','technician','super_admin'] as $role) {
            $actor = User::factory()->create(['role'=>$role,'status'=>'active','customer_id'=>$role==='customer_user' ? $a->id : null]);
            $this->actingAs($actor)->putJson('/api/v2/users/'.$user->id.'/assets',['asset_ids'=>[]])->assertForbidden();
        }
    }
    public function test_customer_asset_reads_and_ticket_creation_require_assignment(): void {
        [$a,$b,$category,$assets,$user,$admin] = $this->fixtures();
        $user->assignedAssets()->attach($assets[0]->id);
        $this->actingAs($user)->getJson('/api/v2/directory/assets')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$assets[0]->id);
        $this->getJson('/api/v2/bootstrap')->assertOk()->assertJsonCount(1,'assets');
        $this->getJson('/api/v2/directory/assets/'.$assets[1]->id)->assertNotFound();
        TicketStatus::create(['name'=>'New','slug'=>'new','sort_order'=>1]);
        $priority = TicketPriority::create(['name'=>'Medium','slug'=>'medium','level'=>2]);
        $body=['subject'=>'Reception device','description'=>'Needs service','category_id'=>$category->id,'priority_id'=>$priority->id,'asset_id'=>$assets[1]->id];
        $this->postJson('/api/v2/tickets',$body)->assertUnprocessable();
        $body['asset_id']=$assets[0]->id;
        $ticket=$this->postJson('/api/v2/tickets',$body)->assertCreated()->json('data.id');
        $user->assignedAssets()->detach();
        $this->getJson('/api/v2/directory/assets')->assertJsonPath('total',0);
        $this->getJson('/api/v2/tickets/'.$ticket)->assertOk();
        $this->postJson('/api/v2/tickets',$body)->assertUnprocessable();
        $body['asset_id']=null;
        $this->postJson('/api/v2/tickets',$body)->assertCreated();
    }
    public function test_creation_with_assignments_is_atomic_and_tenant_scoped(): void {
        [$a,$b,$category,$assets,$user,$admin]=$this->fixtures();
        $body=['name'=>'Reception','email'=>'reception@example.test','role'=>'customer_user','customer_id'=>$a->id,'password'=>'Temporary#12345','password_confirmation'=>'Temporary#12345','asset_ids'=>[$assets[2]->id]];
        $this->actingAs($admin)->postJson('/api/v2/users',$body)->assertUnprocessable();
        $this->assertDatabaseMissing('users',['email'=>$body['email']]);
        $body['asset_ids']=[$assets[0]->id,$assets[1]->id];
        $id=$this->postJson('/api/v2/users',$body)->assertCreated()->json('data.id');
        $this->assertCount(2,User::findOrFail($id)->assignedAssets);
        $ca=User::factory()->create(['role'=>'customer_admin','customer_id'=>$a->id,'status'=>'active']);
        unset($body['customer_id']);$body['email']='another@example.test';
        $this->actingAs($ca)->postJson('/api/v2/users',$body)->assertCreated();
        $this->putJson('/api/v2/users/'.$id.'/assets',['asset_ids'=>[$assets[0]->id,$assets[0]->id]])->assertUnprocessable();
    }
}
