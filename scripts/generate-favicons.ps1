param([string]$Source = 'public/images/Promerty.png')
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @'
using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.IO;
public static class PromertyFavicons {
    public static void Generate(string source, string output) {
        using (var original = new Bitmap(source))
        using (var clean = new Bitmap(original.Width, original.Height, PixelFormat.Format32bppArgb)) {
            int left=original.Width, top=original.Height, right=0, bottom=0, removed=0;
            for (int y=0;y<original.Height;y++) for(int x=0;x<original.Width;x++) {
                Color p=original.GetPixel(x,y);
                // Red matte residue in this export has alpha <= 4. Keep every visible gold RGB value.
                if (p.A<=16) { if(p.A>0) removed++; continue; }
                clean.SetPixel(x,y,p);
                left=Math.Min(left,x); top=Math.Min(top,y); right=Math.Max(right,x); bottom=Math.Max(bottom,y);
            }
            var bounds=Rectangle.FromLTRB(left,top,right+1,bottom+1);
            int[] sizes={16,32,48,192,512};
            foreach(int size in sizes) using(var icon=new Bitmap(size,size,PixelFormat.Format32bppArgb)) {
                using(var g=Graphics.FromImage(icon)) {
                    g.Clear(Color.Transparent);
                    g.CompositingMode=CompositingMode.SourceCopy;
                    g.CompositingQuality=CompositingQuality.HighQuality;
                    g.InterpolationMode=InterpolationMode.HighQualityBicubic;
                    g.PixelOffsetMode=PixelOffsetMode.HighQuality;
                    double scale=(size-2.0)/Math.Max(bounds.Width,bounds.Height);
                    float w=(float)(bounds.Width*scale),h=(float)(bounds.Height*scale);
                    using(var attr=new ImageAttributes()) {
                        attr.SetWrapMode(WrapMode.TileFlipXY);
                        float dx=(size-w)/2,dy=(size-h)/2;
                        g.DrawImage(clean,new PointF[]{new PointF(dx,dy),new PointF(dx+w,dy),new PointF(dx,dy+h)},bounds,GraphicsUnit.Pixel,attr);
                    }
                }
                icon.Save(Path.Combine(output,"favicon-"+size+"x"+size+".png"),ImageFormat.Png);
            }
            using(var writer=new BinaryWriter(File.Create(Path.Combine(output,"favicon.ico")))) {
                writer.Write((ushort)0);writer.Write((ushort)1);writer.Write((ushort)3);
                int offset=6+16*3;
                for(int i=0;i<3;i++) {
                    int size=sizes[i]; byte[] png=File.ReadAllBytes(Path.Combine(output,"favicon-"+size+"x"+size+".png"));
                    writer.Write((byte)size);writer.Write((byte)size);writer.Write((byte)0);writer.Write((byte)0);
                    writer.Write((ushort)1);writer.Write((ushort)32);writer.Write(png.Length);writer.Write(offset);offset+=png.Length;
                }
                for(int i=0;i<3;i++)writer.Write(File.ReadAllBytes(Path.Combine(output,"favicon-"+sizes[i]+"x"+sizes[i]+".png")));
            }
            Console.WriteLine("Source unchanged; cleaned derivative bounds: "+bounds+"; residue pixels removed: "+removed);
        }
    }
}
'@
[PromertyFavicons]::Generate((Resolve-Path -LiteralPath $Source).Path, (Resolve-Path -LiteralPath 'public').Path)
