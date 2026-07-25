import { requireUser,accessibleCompanyIds } from "@/lib/session";
import { query } from "@/lib/db";
import { documentStatus,formatBGN,formatDate } from "@/lib/format";
export default async function DashboardPage(){
 const {session,profile}=await requireUser();const ids=await accessibleCompanyIds(session.user.id,profile.global_role);
 const [vehicles,documents,expenses,audits]=await Promise.all([
  query<any>("select id,status,registration_number from vehicles where company_id=any($1::uuid[])",[ids]),
  query<any>(`select d.id,d.document_type,d.end_date,v.registration_number from vehicle_documents d join vehicles v on v.id=d.vehicle_id where d.company_id=any($1::uuid[]) order by d.end_date limit 10`,[ids]),
  query<any>("select gross_amount,expense_date from expenses where company_id=any($1::uuid[])",[ids]),
  query<any>("select action,entity_type,created_at from audit_log where company_id=any($1::uuid[]) or $2=true order by created_at desc limit 8",[ids,profile.global_role==="main_admin"])
 ]);
 const month=new Date().toISOString().slice(0,7),monthly=expenses.filter(e=>String(e.expense_date).startsWith(month)).reduce((s,e)=>s+Number(e.gross_amount||0),0);
 const expired=documents.filter(d=>documentStatus(d.end_date).key==="expired").length,soon=documents.filter(d=>["soon","urgent"].includes(documentStatus(d.end_date).key)).length;
 return <><div className="page-head"><div><h1>Начално табло</h1><div className="muted">Обобщение на достъпните компании</div></div></div>
 <div className="grid grid-4"><div className="card"><div className="muted">Автомобили</div><div className="card-value">{vehicles.length}</div></div><div className="card"><div className="muted">В сервиз</div><div className="card-value">{vehicles.filter(v=>v.status==="service").length}</div></div><div className="card"><div className="muted">Изтичащи</div><div className="card-value">{soon}</div></div><div className="card"><div className="muted">Изтекли</div><div className="card-value">{expired}</div></div></div>
 <div className="grid grid-2" style={{marginTop:16}}><section className="panel"><div className="panel-head"><h2>Предстоящи срокове</h2></div>{documents.map(d=>{const s=documentStatus(d.end_date);return <div className="deadline" key={d.id}><div><strong>{d.registration_number}</strong><div className="muted">{d.document_type} · {formatDate(d.end_date)}</div></div><span className={`badge badge-${s.key}`}>{s.label}</span></div>})}</section>
 <section className="panel"><div className="panel-head"><h2>Разходи този месец</h2></div><div className="card-value">{formatBGN(monthly)}</div><div style={{marginTop:22}}><h2>Последна активност</h2></div>{audits.map((a,i)=><div className="deadline" key={i}><div><strong>{a.action}</strong><div className="muted">{a.entity_type}</div></div><span className="muted">{formatDate(a.created_at)}</span></div>)}</section></div></>
}
