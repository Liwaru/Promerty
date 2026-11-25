// Query policy assignments only. No account, policy, logon, privilege or service changes.
using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Security.Principal;
public static class LocalLogonRightsReadOnly {
    [StructLayout(LayoutKind.Sequential)] struct ObjectAttributes {public uint Length;public IntPtr RootDirectory,ObjectName;public uint Attributes;public IntPtr SecurityDescriptor,SecurityQualityOfService;}
    [StructLayout(LayoutKind.Sequential)] struct UnicodeString {public ushort Length,MaximumLength;public IntPtr Buffer;}
    [DllImport("advapi32.dll")] static extern uint LsaOpenPolicy(IntPtr system,ref ObjectAttributes attributes,uint access,out IntPtr policy);
    [DllImport("advapi32.dll")] static extern uint LsaEnumerateAccountsWithUserRight(IntPtr policy,ref UnicodeString right,out IntPtr buffer,out uint count);
    [DllImport("advapi32.dll")] static extern uint LsaNtStatusToWinError(uint status);
    [DllImport("advapi32.dll")] static extern uint LsaFreeMemory(IntPtr buffer);
    [DllImport("advapi32.dll")] static extern uint LsaClose(IntPtr policy);
    public static Dictionary<string,object> Read() {
        ObjectAttributes attrs=new ObjectAttributes();attrs.Length=(uint)Marshal.SizeOf(typeof(ObjectAttributes));IntPtr policy;
        uint status=LsaOpenPolicy(IntPtr.Zero,ref attrs,0x00000001|0x00000800,out policy);
        if(status!=0)throw new Win32Exception((int)LsaNtStatusToWinError(status),"Read-only local policy query unavailable");
        Dictionary<string,object> result=new Dictionary<string,object>();
        try{foreach(string name in new string[]{"SeBatchLogonRight","SeDenyBatchLogonRight","SeInteractiveLogonRight","SeDenyInteractiveLogonRight","SeRemoteInteractiveLogonRight","SeDenyRemoteInteractiveLogonRight","SeNetworkLogonRight","SeDenyNetworkLogonRight","SeServiceLogonRight","SeDenyServiceLogonRight"}){
            UnicodeString right=new UnicodeString();right.Buffer=Marshal.StringToHGlobalUni(name);right.Length=(ushort)(name.Length*2);right.MaximumLength=(ushort)(right.Length+2);IntPtr buffer=IntPtr.Zero;uint count=0;
            try{status=LsaEnumerateAccountsWithUserRight(policy,ref right,out buffer,out count);List<object> entries=new List<object>();
                if(status!=0 && status!=0x8000001A && status!=0xC0000034){result[name]=new {status="UNVERIFIED",win32=LsaNtStatusToWinError(status)};continue;}
                for(int i=0;i<count;i++){string sid=new SecurityIdentifier(Marshal.ReadIntPtr(buffer,i*IntPtr.Size)).Value;string account;try{account=new SecurityIdentifier(sid).Translate(typeof(NTAccount)).Value;}catch{account="UNRESOLVED";}entries.Add(new{sid=sid,account=account});}
                result[name]=new {status="READ_ONLY",accounts=entries};
            }finally{Marshal.FreeHGlobal(right.Buffer);if(buffer!=IntPtr.Zero)LsaFreeMemory(buffer);}
        }}finally{LsaClose(policy);}return result;
    }
}
