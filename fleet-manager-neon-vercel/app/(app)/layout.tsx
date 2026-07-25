import { Sidebar } from "@/components/sidebar";
import { SignOutButton } from "@/components/sign-out";
import { requireUser } from "@/lib/session";
export default async function AppLayout({children}:{children:React.ReactNode}){
 const {profile}=await requireUser();
 return <div className="shell"><Sidebar/><main className="main"><header className="topbar"><div><strong>{profile.full_name}</strong><div className="muted" style={{fontSize:12}}>{profile.global_role}</div></div><SignOutButton/></header><div className="content">{children}</div></main></div>
}
