"use server";
import { revalidatePath } from "next/cache";
import { put } from "@vercel/blob";
import { query,one } from "@/lib/db";
import { requireUser,requireMainAdmin,accessibleCompanyIds,canManageCompany } from "@/lib/session";
import { writeAudit } from "@/lib/audit";

function value(fd:FormData,key:string){return String(fd.get(key)||"").trim()}
async function upload(file:FormDataEntryValue|null,companyId:string,folder:string){
 if(!(file instanceof File)||!file.size)return null;
 const blob=await put(`${companyId}/${folder}/${Date.now()}-${file.name}`,file,{access:"private",addRandomSuffix:true});
 return blob.pathname;
}

export async function createCompany(fd:FormData){
 const {session}=await requireMainAdmin();
 const row=await one<{id:string}>(`insert into companies(name,eik,address,manager_name,phone,email) values($1,$2,$3,$4,$5,$6) returning id`,
 [value(fd,"name"),value(fd,"eik")||null,value(fd,"address")||null,value(fd,"manager_name")||null,value(fd,"phone")||null,value(fd,"email")||null]);
 await writeAudit({actorUserId:session.user.id,action:"company.created",entityType:"company",entityId:row?.id,companyId:row?.id,details:{name:value(fd,"name")}});
 revalidatePath("/companies");
}

export async function createVehicle(fd:FormData){
 const {session,profile}=await requireUser(); const companyId=value(fd,"company_id");
 if(!await canManageCompany(session.user.id,profile.global_role,companyId,"vehicles.manage"))throw new Error("Нямате право.");
 const photo=await upload(fd.get("photo"),companyId,"vehicles");
 const row=await one<{id:string}>(`insert into vehicles(company_id,registration_number,make,model,vin,year,vehicle_type,ownership_type,mileage,status,photo_path,notes,created_by)
 values($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) returning id`,
 [companyId,value(fd,"registration_number").toUpperCase(),value(fd,"make"),value(fd,"model"),value(fd,"vin").toUpperCase()||null,Number(value(fd,"year"))||null,value(fd,"vehicle_type"),value(fd,"ownership_type"),Number(value(fd,"mileage"))||0,value(fd,"status")||"active",photo,value(fd,"notes")||null,session.user.id]);
 await writeAudit({actorUserId:session.user.id,action:"vehicle.created",entityType:"vehicle",entityId:row?.id,companyId});
 revalidatePath("/vehicles");revalidatePath("/dashboard");
}

export async function createDocument(fd:FormData){
 const {session,profile}=await requireUser();const companyId=value(fd,"company_id");
 if(!await canManageCompany(session.user.id,profile.global_role,companyId,"documents.manage"))throw new Error("Нямате право.");
 const attachment=await upload(fd.get("attachment"),companyId,"documents");
 const row=await one<{id:string}>(`insert into vehicle_documents(company_id,vehicle_id,document_type,document_number,provider,start_date,end_date,cost,file_path,notes,created_by)
 values($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11) returning id`,
 [companyId,value(fd,"vehicle_id"),value(fd,"document_type"),value(fd,"document_number")||null,value(fd,"provider")||null,value(fd,"start_date")||null,value(fd,"end_date"),Number(value(fd,"cost"))||0,attachment,value(fd,"notes")||null,session.user.id]);
 await writeAudit({actorUserId:session.user.id,action:"document.created",entityType:"vehicle_document",entityId:row?.id,companyId});
 revalidatePath("/documents");revalidatePath("/dashboard");
}

export async function createServiceRecord(fd:FormData){
 const {session,profile}=await requireUser();const companyId=value(fd,"company_id");
 if(!await canManageCompany(session.user.id,profile.global_role,companyId,"service.manage"))throw new Error("Нямате право.");
 const invoice=await upload(fd.get("invoice"),companyId,"service");
 const row=await one<{id:string}>(`insert into service_records(company_id,vehicle_id,service_date,mileage,provider,work_performed,parts,cost,next_service_date,next_service_mileage,invoice_path,notes,created_by)
 values($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13) returning id`,
 [companyId,value(fd,"vehicle_id"),value(fd,"service_date"),Number(value(fd,"mileage"))||null,value(fd,"provider")||null,value(fd,"work_performed"),value(fd,"parts")||null,Number(value(fd,"cost"))||0,value(fd,"next_service_date")||null,Number(value(fd,"next_service_mileage"))||null,invoice,value(fd,"notes")||null,session.user.id]);
 await writeAudit({actorUserId:session.user.id,action:"service.created",entityType:"service_record",entityId:row?.id,companyId});
 revalidatePath("/service");
}

export async function createExpense(fd:FormData){
 const {session,profile}=await requireUser();const companyId=value(fd,"company_id");
 if(!await canManageCompany(session.user.id,profile.global_role,companyId,"expenses.manage"))throw new Error("Нямате право.");
 const document=await upload(fd.get("document"),companyId,"expenses");
 const row=await one<{id:string}>(`insert into expenses(company_id,vehicle_id,expense_date,category,supplier,gross_amount,currency,document_path,notes,created_by)
 values($1,$2,$3,$4,$5,$6,'BGN',$7,$8,$9) returning id`,
 [companyId,value(fd,"vehicle_id")||null,value(fd,"expense_date"),value(fd,"category"),value(fd,"supplier")||null,Number(value(fd,"gross_amount"))||0,document,value(fd,"notes")||null,session.user.id]);
 await writeAudit({actorUserId:session.user.id,action:"expense.created",entityType:"expense",entityId:row?.id,companyId});
 revalidatePath("/expenses");revalidatePath("/dashboard");
}

export async function sendMessage(fd:FormData){
 const {session,profile}=await requireUser();const targetType=value(fd,"target_type"),target=value(fd,"target_value"),companyId=value(fd,"company_id")||null;
 const message=await one<{id:string}>(`insert into messages(sender_user_id,company_id,subject,body,priority,target_type,target_value) values($1,$2,$3,$4,$5,$6,$7) returning id`,
 [session.user.id,companyId,value(fd,"subject"),value(fd,"body"),value(fd,"priority")||"normal",targetType,target]);
 let recipients:string[]=[];
 if(targetType==="user")recipients=[target];
 if(targetType==="company"){const rows=await query<{user_id:string}>("select user_id from company_users where company_id=$1 and active=true",[target]);recipients=rows.map(r=>r.user_id)}
 if(targetType==="role"){const rows=await query<{user_id:string}>("select user_id from app_profiles where global_role=$1 and active=true",[target]);recipients=rows.map(r=>r.user_id)}
 for(const userId of [...new Set(recipients)])await query("insert into message_recipients(message_id,user_id) values($1,$2) on conflict do nothing",[message?.id,userId]);
 await writeAudit({actorUserId:session.user.id,action:"message.sent",entityType:"message",entityId:message?.id,companyId,details:{targetType,target,recipients:recipients.length}});
 revalidatePath("/messages");
}
