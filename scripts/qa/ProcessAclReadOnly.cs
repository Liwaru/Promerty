// Phase 3C: query an existing process token and evaluate DACLs only.
// No impersonation, privilege adjustment, process launch, file write or ACL mutation API.
using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Security.Principal;
using System.Text;

public sealed class ProcessAclReadOnly : IDisposable {
    [StructLayout(LayoutKind.Sequential)] struct SidAttributes { public IntPtr Sid; public uint Attributes; }
    [StructLayout(LayoutKind.Sequential)] struct GroupFirst { public uint Count; public SidAttributes First; }
    [StructLayout(LayoutKind.Sequential)] struct Mapping { public uint Read, Write, Execute, All; }
    [DllImport("kernel32.dll", SetLastError=true)] static extern IntPtr OpenProcess(uint access, bool inherit, int pid);
    [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr handle);
    [DllImport("advapi32.dll", SetLastError=true)] static extern bool OpenProcessToken(IntPtr process, uint access, out IntPtr token);
    [DllImport("advapi32.dll", SetLastError=true)] static extern bool DuplicateToken(IntPtr token, int level, out IntPtr duplicate);
    [DllImport("advapi32.dll")] static extern bool IsTokenRestricted(IntPtr token);
    [DllImport("advapi32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern bool LookupPrivilegeName(string system, IntPtr luid, StringBuilder name, ref int length);
    [DllImport("advapi32.dll", SetLastError=true)] static extern bool GetTokenInformation(IntPtr token, int kind, IntPtr data, int size, out int required);
    [DllImport("advapi32.dll", SetLastError=true)] static extern bool AccessCheck(byte[] descriptor, IntPtr token, uint desired, ref Mapping mapping, IntPtr privileges, ref uint privilegeLength, out uint granted, out bool status);
    IntPtr token = IntPtr.Zero, evaluationToken = IntPtr.Zero;
    public ProcessAclReadOnly(int pid) {
        IntPtr process=OpenProcess(0x1000,false,pid);
        if(process==IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error(),"OpenProcess query denied");
        try { if(!OpenProcessToken(process,0x0008|0x0002,out token)) throw new Win32Exception(Marshal.GetLastWin32Error(),"Token query/duplicate denied"); }
        finally { CloseHandle(process); }
        // AccessCheck requires an impersonation-type token. It is NEVER attached to a thread/process.
        if(!DuplicateToken(token,2,out evaluationToken)) { int error=Marshal.GetLastWin32Error(); Dispose(); throw new Win32Exception(error,"AccessCheck token copy denied"); }
    }
    IntPtr Information(int kind) {
        int length; GetTokenInformation(token,kind,IntPtr.Zero,0,out length);
        if(length<=0) throw new Win32Exception(Marshal.GetLastWin32Error(),"Token field unavailable");
        IntPtr memory=Marshal.AllocHGlobal(length);
        if(!GetTokenInformation(token,kind,memory,length,out length)) { int error=Marshal.GetLastWin32Error();Marshal.FreeHGlobal(memory);throw new Win32Exception(error); }
        return memory;
    }
    int Integer(int kind) { IntPtr p=Information(kind);try{return Marshal.ReadInt32(p);}finally{Marshal.FreeHGlobal(p);} }
    static string Account(string sid) { try{return new SecurityIdentifier(sid).Translate(typeof(NTAccount)).Value;}catch{return "UNRESOLVED";} }
    public Dictionary<string,object> Identity() {
        Dictionary<string,object> result=new Dictionary<string,object>();
        IntPtr p=Information(1);string user;
        try {user=new SecurityIdentifier(Marshal.ReadIntPtr(p)).Value;}finally{Marshal.FreeHGlobal(p);}
        result["sid"]=user;result["account"]=Account(user);result["elevated"]=Integer(20)!=0;result["elevationType"]=Integer(18);
        result["hasRestrictions"]=Integer(21)!=0;
        result["isRestrictedToken"]=IsTokenRestricted(token);
        p=Information(11);try{result["restrictingSidCount"]=Marshal.ReadInt32(p);}finally{Marshal.FreeHGlobal(p);}
        p=Information(25);try{string sid=new SecurityIdentifier(Marshal.ReadIntPtr(p)).Value;result["integritySid"]=sid;string[] parts=sid.Split('-');int rid=Int32.Parse(parts[parts.Length-1]);result["integrity"]=rid>=16384?"System":rid>=12288?"High":rid>=8192?"Medium":rid>=4096?"Low":"Untrusted";}finally{Marshal.FreeHGlobal(p);}
        List<object> groups=new List<object>();p=Information(2);
        try {int count=Marshal.ReadInt32(p);int offset=(int)Marshal.OffsetOf(typeof(GroupFirst),"First");int stride=Marshal.SizeOf(typeof(SidAttributes));
            for(int i=0;i<count;i++){SidAttributes group=(SidAttributes)Marshal.PtrToStructure(IntPtr.Add(p,offset+i*stride),typeof(SidAttributes));string sid=new SecurityIdentifier(group.Sid).Value;groups.Add(new {sid=sid,account=Account(sid),enabled=(group.Attributes&4)!=0,denyOnly=(group.Attributes&16)!=0,attributes="0x"+group.Attributes.ToString("X")});}
        }finally{Marshal.FreeHGlobal(p);}result["groups"]=groups;
        List<object> privileges=new List<object>();p=Information(3);
        try{int count=Marshal.ReadInt32(p);for(int i=0;i<count;i++){IntPtr luid=IntPtr.Add(p,4+i*12);int length=256;StringBuilder name=new StringBuilder(length);bool ok=LookupPrivilegeName(null,luid,name,ref length);uint attrs=(uint)Marshal.ReadInt32(luid,8);privileges.Add(new {name=ok?name.ToString():"UNVERIFIED",enabled=(attrs&2)!=0,attributes="0x"+attrs.ToString("X")});}}finally{Marshal.FreeHGlobal(p);}result["privileges"]=privileges;
        return result;
    }
    public Dictionary<string,object> Check(byte[] descriptor) {
        Mapping mapping=new Mapping { Read=0x120089, Write=0x120116, Execute=0x1200A0, All=0x1F01FF };
        uint length=4096,granted;bool status;IntPtr memory=Marshal.AllocHGlobal((int)length);
        try {
            if(!AccessCheck(descriptor,evaluationToken,0x02000000,ref mapping,memory,ref length,out granted,out status)) throw new Win32Exception(Marshal.GetLastWin32Error(),"DACL AccessCheck unavailable");
            return new Dictionary<string,object> { {"accessStatus",status},{"grantedMask","0x"+granted.ToString("X")},
                {"readData",(granted&1)!=0},{"writeDataOrAddFile",(granted&2)!=0},{"appendOrAddSubdirectory",(granted&4)!=0},
                {"executeOrTraverse",(granted&32)!=0},{"deleteChild",(granted&64)!=0},{"delete",(granted&0x10000)!=0},
                {"writeDac",(granted&0x40000)!=0},{"writeOwner",(granted&0x80000)!=0} };
        }finally{Marshal.FreeHGlobal(memory);}
    }
    public void Dispose() {if(evaluationToken!=IntPtr.Zero){CloseHandle(evaluationToken);evaluationToken=IntPtr.Zero;}if(token!=IntPtr.Zero){CloseHandle(token);token=IntPtr.Zero;}}
}
