import { useRef, useEffect, useCallback } from 'react';

interface LaserFlowProps {
  color?: string;
  flowSpeed?: number;
  beamWidth?: number;
  fogIntensity?: number;
  className?: string;
}

/* ── Vertex shader ── */
const VERT = `
attribute vec2 a_position;
void main() {
  gl_Position = vec4(a_position, 0.0, 1.0);
}
`;

/* ── Fragment shader — soft laser beam + atmospheric glow ── */
const FRAG = `
#ifdef GL_ES
precision mediump float;
#endif

uniform vec2  u_resolution;
uniform float u_time;
uniform vec3  u_color;
uniform float u_flowSpeed;
uniform float u_beamWidth;
uniform float u_fogIntensity;

// ---- simplex noise ----
vec3 mod289(vec3 x){ return x - floor(x*(1.0/289.0))*289.0; }
vec2 mod289v(vec2 x){ return x - floor(x*(1.0/289.0))*289.0; }
vec3 permute(vec3 x){ return mod289(((x*34.0)+1.0)*x); }

float snoise(vec2 v){
  const vec4 C = vec4(0.211324865405187,0.366025403784439,
                      -0.577350269189626,0.024390243902439);
  vec2 i = floor(v + dot(v,C.yy));
  vec2 x0= v - i + dot(i,C.xx);
  vec2 i1= (x0.x>x0.y) ? vec2(1.0,0.0) : vec2(0.0,1.0);
  vec4 x12= x0.xyxy + C.xxzz;
  x12.xy -= i1;
  i = mod289v(i);
  vec3 p = permute(permute(i.y+vec3(0.0,i1.y,1.0))+i.x+vec3(0.0,i1.x,1.0));
  vec3 m = max(0.5-vec3(dot(x0,x0),dot(x12.xy,x12.xy),dot(x12.zw,x12.zw)),0.0);
  m = m*m; m = m*m;
  vec3 x_ = 2.0*fract(p*C.www)-1.0;
  vec3 h = abs(x_)-0.5;
  vec3 ox= floor(x_+0.5);
  vec3 a0= x_-ox;
  m *= 1.79284291400159-0.85373472095314*(a0*a0+h*h);
  vec3 g;
  g.x = a0.x*x0.x  + h.x*x0.y;
  g.yz= a0.yz*x12.xz + h.yz*x12.yw;
  return 130.0*dot(m,g);
}

float fbm(vec2 p){
  float f=0.0, w=0.5;
  for(int i=0;i<5;i++){ f+=w*snoise(p); p*=2.0; w*=0.5; }
  return f;
}

void main(){
  if(u_resolution.x < 1.0 || u_resolution.y < 1.0){
    gl_FragColor = vec4(0.0,0.0,0.0,1.0);
    return;
  }

  vec2 uv = gl_FragCoord.xy / u_resolution;
  float aspect = u_resolution.x / u_resolution.y;

  float cx = (uv.x - 0.5) * aspect;   // centered x
  float cy = uv.y;                      // 0=bottom 1=top
  float t  = u_time * u_flowSpeed;

  // Subtle horizontal sway
  float sway = snoise(vec2(cy * 1.5, t * 0.4)) * 0.008;
  cx += sway;

  // ---- 1. Beam: very thin core + soft glow layers ----
  // Width grows from razor-thin at bottom to wide at top
  float baseW = u_beamWidth * 0.006;
  float growW = u_beamWidth * 0.18 * cy * cy;
  float totalW = baseW + growW;

  // Thin bright core
  float core = exp(-pow(cx / totalW, 2.0));
  core *= smoothstep(0.0, 0.05, cy);

  // Medium glow layer (3x wider)
  float glow1 = exp(-pow(cx / (totalW * 3.5), 2.0)) * 0.6;
  glow1 *= smoothstep(0.0, 0.1, cy);

  // Wide ambient glow (8x wider)
  float glow2 = exp(-pow(cx / (totalW * 9.0), 2.0)) * 0.2;
  glow2 *= smoothstep(0.0, 0.15, cy);

  // Fade beam at very top
  float topFade = 1.0 - smoothstep(0.85, 1.0, cy);
  core  *= topFade;
  glow1 *= topFade;
  glow2 *= 1.0 - smoothstep(0.9, 1.0, cy);

  // ---- 2. Flowing fog near base ----
  float fogN = fbm(vec2(cx * 2.5 + t * 0.15, cy * 1.8 - t * 0.3));
  float fogMask = exp(-pow(cx / (u_beamWidth * 0.35), 2.0));
  // Fog concentrated in bottom third
  fogMask *= smoothstep(0.0, 0.08, cy) * (1.0 - smoothstep(0.15, 0.55, cy));
  float fog = fogMask * (0.5 + 0.5 * fogN) * u_fogIntensity * 0.5;

  // ---- 3. Floating particles / wisps ----
  float wisps = 0.0;
  for(float i = 0.0; i < 4.0; i++){
    float seed = i * 2.399;
    float px = cx * (2.0 + i) + sin(t * 0.2 + seed) * 0.08;
    float py = cy * (1.5 + i * 0.3) - t * (0.25 + i * 0.06);
    float w = snoise(vec2(px, py)) * 0.5 + 0.5;
    w = pow(w, 4.0);
    float wmask = exp(-pow(cx / (totalW * 5.0), 2.0));
    wisps += w * wmask * 0.035;
  }
  wisps *= smoothstep(0.0, 0.1, cy) * topFade;

  // ---- 4. Color composition ----
  // Brighter emerald for core, standard emerald for glow
  vec3 coreCol = u_color * 1.6 + vec3(0.15, 0.05, 0.2); // bright emerald-white
  coreCol = min(coreCol, vec3(1.0));
  vec3 glowCol = u_color;
  vec3 ambCol  = u_color * 0.7;

  vec3 col = vec3(0.0);
  col += coreCol * core;           // razor-thin bright center
  col += glowCol * glow1;          // medium emerald glow
  col += ambCol  * glow2;          // wide ambient emerald
  col += glowCol * fog;            // base fog
  col += ambCol  * wisps;          // floating particles

  // Very subtle vignette
  float vig = 1.0 - length(uv - vec2(0.5)) * 0.3;
  col *= vig;

  // Dark background tint (very subtle emerald in dark areas)
  col += u_color * 0.008;

  gl_FragColor = vec4(col, 1.0);
}
`;

function hexToRgb(hex: string): [number, number, number] {
  const r = parseInt(hex.slice(1, 3), 16) / 255;
  const g = parseInt(hex.slice(3, 5), 16) / 255;
  const b = parseInt(hex.slice(5, 7), 16) / 255;
  return [r, g, b];
}

export default function LaserFlow({
  color = '#A855F7',
  flowSpeed = 0.35,
  beamWidth = 0.5,
  fogIntensity = 0.5,
  className = '',
}: LaserFlowProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const rafRef = useRef<number>(0);
  const glRef = useRef<WebGLRenderingContext | null>(null);

  /* ---- WebGL bootstrap (runs once) ---- */
  const initGL = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return null;

    const gl = canvas.getContext('webgl', {
      alpha: false,
      antialias: false,
      preserveDrawingBuffer: false,
    });
    if (!gl) {
      return null;
    }

    function compile(type: number, src: string) {
      const s = gl!.createShader(type)!;
      gl!.shaderSource(s, src);
      gl!.compileShader(s);
      if (!gl!.getShaderParameter(s, gl!.COMPILE_STATUS)) {
        return null;
      }
      return s;
    }

    const vs = compile(gl.VERTEX_SHADER, VERT);
    const fs = compile(gl.FRAGMENT_SHADER, FRAG);
    if (!vs || !fs) return null;

    const prog = gl.createProgram()!;
    gl.attachShader(prog, vs);
    gl.attachShader(prog, fs);
    gl.linkProgram(prog);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
      return null;
    }
    gl.useProgram(prog);

    // Full-screen quad
    const buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(
      gl.ARRAY_BUFFER,
      new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]),
      gl.STATIC_DRAW
    );
    const aPos = gl.getAttribLocation(prog, 'a_position');
    gl.enableVertexAttribArray(aPos);
    gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);

    return { gl, prog, vs, fs, buf };
  }, []);

  useEffect(() => {
    const result = initGL();
    if (!result) return;

    const { gl, prog, vs, fs, buf } = result;
    glRef.current = gl;

    // Uniform locations
    const uRes          = gl.getUniformLocation(prog, 'u_resolution');
    const uTime         = gl.getUniformLocation(prog, 'u_time');
    const uColor        = gl.getUniformLocation(prog, 'u_color');
    const uFlowSpeed    = gl.getUniformLocation(prog, 'u_flowSpeed');
    const uBeamWidth    = gl.getUniformLocation(prog, 'u_beamWidth');
    const uFogIntensity = gl.getUniformLocation(prog, 'u_fogIntensity');

    const [r, g, b] = hexToRgb(color);
    gl.uniform3f(uColor, r, g, b);
    gl.uniform1f(uFlowSpeed, flowSpeed);
    gl.uniform1f(uBeamWidth, beamWidth);
    gl.uniform1f(uFogIntensity, fogIntensity);

    // ---- Resize ----
    let w = 0, h = 0;

    function resize() {
      const container = containerRef.current;
      const canvas = canvasRef.current;
      if (!container || !canvas) return;

      const dpr = Math.min(window.devicePixelRatio, 2);
      const cw = Math.round(container.clientWidth * dpr);
      const ch = Math.round(container.clientHeight * dpr);
      if (cw < 1 || ch < 1) return;         // not visible yet
      if (w === cw && h === ch) return;      // unchanged

      w = cw;
      h = ch;
      canvas.width = w;
      canvas.height = h;
      gl.viewport(0, 0, w, h);
      gl.uniform2f(uRes, w, h);
    }

    const ro = new ResizeObserver(resize);
    ro.observe(containerRef.current!);

    // Also try an immediate + delayed resize to catch first paint
    resize();
    const t1 = setTimeout(resize, 50);
    const t2 = setTimeout(resize, 200);

    // ---- Render loop ----
    const start = performance.now();

    function frame() {
      if (w < 1 || h < 1) {
        resize();                            // keep trying
        rafRef.current = requestAnimationFrame(frame);
        return;
      }
      const elapsed = (performance.now() - start) / 1000;
      gl.uniform1f(uTime, elapsed);
      gl.drawArrays(gl.TRIANGLES, 0, 6);
      rafRef.current = requestAnimationFrame(frame);
    }
    rafRef.current = requestAnimationFrame(frame);

    return () => {
      cancelAnimationFrame(rafRef.current);
      clearTimeout(t1);
      clearTimeout(t2);
      ro.disconnect();
      gl.deleteProgram(prog);
      gl.deleteShader(vs);
      gl.deleteShader(fs);
      gl.deleteBuffer(buf);
      glRef.current = null;
    };
  }, [color, flowSpeed, beamWidth, fogIntensity, initGL]);

  return (
    <div
      ref={containerRef}
      className={`absolute inset-0 ${className}`}
      style={{ background: '#000' }}
    >
      <canvas
        ref={canvasRef}
        style={{
          display: 'block',
          position: 'absolute',
          top: 0,
          left: 0,
          width: '100%',
          height: '100%',
        }}
      />
    </div>
  );
}
