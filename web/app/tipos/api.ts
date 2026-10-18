/**
 * Formas de la API que consume la interfaz.
 *
 * Los nombres de las respuestas son los que devuelve el backend. Se declaran
 * aquí para que un cambio en la API se note en un solo lugar y no repartido
 * en todas las pantallas.
 *
 * ---------------------------------------------------------------------
 | POR QUE LOS MONTOS Y CANTIDADES SON `string` Y NO `number`
 * ---------------------------------------------------------------------
 | El backend manda las cantidades y los montos como cadena, y eso es a
 | propósito: la API trabaja con aritmética exacta de decimales (ver
 | `App\Support\Cantidad`). Si esta capa los convirtiera a `number`, el
 * navegador empezaria a calcular con coma flotante y los totales de la
 * pantalla dejarian de cuadrar con los del servidor, que es exactamente el
 * defecto que el backend evita.
 *
 | Para sumar o comparar, se delega en el servidor o se usan las utilidades
 * de `~/app/utils/valores.ts`.
 */

/** Rol de la persona dentro de la ferreteria. */
export type Rol = 'administrador' | 'gerente' | 'vendedor' | 'bodeguero';

/** Lo que la API devuelve de quien esta operando. */
export interface FerreteriaSession {
  id: string;
  nombre: string;
  email: string;
  rol: Rol;
  empresa: string;
  sucursal_id: string | null;
  puede_administrar_catalogo: boolean;
  puede_cerrar_caja: boolean;
  puede_mover_entre_sucursales: boolean;
  puede_anular: boolean;
  puede_administrar_personas: boolean;
}

/**
 * Una ferreteria del selector de entrada.
 *
 * SOLO tiene `slug` y `nombre`: es lo que devuelve la vista pública. No hay
 * RUT, razon social, direccion ni telefono, y no deberia agregarse sin
 * revisar por que se expone antes del acceso.
 */
export interface FerreteriaPublica {
  slug: string;
  nombre: string;
}

export interface Sucursal {
  id: string;
  codigo: string;
  nombre: string;
  direccion: string | null;
  telefono: string | null;
  activo: boolean;
}

export interface UnidadMedida {
  id: string;
  codigo: string;
  nombre: string;
  es_base: boolean;
  unidad_base_id: string | null;
  unidades_por_base: string;
  activo: boolean;
}

export interface Categoria {
  id: string;
  codigo: string;
  nombre: string;
}

export interface Marca {
  id: string;
  codigo: string;
  nombre: string;
}

/**
 * Producto base del catálogo.
 *
 * NO tiene `precio` ni `stock`, y no es un olvido: el precio y el stock son
 * de la variante. Un producto que mostrara un precio propio induciria a
 * cobrar por el producto en vez de por la fila concreta.
 */
export interface Producto {
  id: string;
  sku: string;
  nombre: string;
  descripcion: string | null;
  categoria: { id: string; nombre: string } | null;
  marca: { id: string; nombre: string } | null;
  unidad_base: { id: string; codigo: string; nombre: string } | null;
  stock_minimo: string;
  activo: boolean;
  variantes: number | null;
}

export interface Variante {
  id: string;
  producto_id: string;
  sku: string;
  nombre: string;
  etiqueta: string;
  medida: string | null;
  material: string | null;
  acabado: string | null;
  color: string | null;
  contenido: string;
  unidad_contenido: { id: string; codigo: string; nombre: string };
  unidad_venta: { id: string; codigo: string; nombre: string };
  codigo_barras: string | null;
  precio_venta: string;
  costo_referencia: string | null;
  activo: boolean;
}

/** Producto con su matriz de variantes completa. */
export interface ProductoDetalle {
  producto: Producto;
  /** Ejes que esta linea de producto usa de verdad. */
  ejes: Record<string, string>;
  variantes: Variante[];
}

/** Precio de una variante en una lista. El precio depende de las dos. */
export interface ListaPrecio {
  id: string;
  nombre: string;
  tipo: 'consumidor' | 'mayorista';
  sucursal_id: string | null;
  activo: boolean;
  vigente_desde: string | null;
  vigente_hasta: string | null;
}

/** Saldo de una variante en una sucursal. */
export interface SaldoStock {
  id: string;
  variante_id: string;
  sku: string | null;
  variante: string | null;
  sucursal_id: string;
  sucursal: string | null;
  cantidad: string;
  cantidad_reservada: string;
  /** Se deriva en el servidor: cantidad menos lo comprometido. */
  disponible: string;
  unidad: string | null;
}

export interface MovimientoStock {
  id: string;
  tipo: string;
  cantidad: string;
  unidad: string | null;
  glosa: string | null;
  variante: string | null;
  sucursal: string | null;
  usuario: string | null;
  ocurrido_en: string;
}

export interface TransferenciaItem {
  variante_id: string;
  sku: string | null;
  variante: string | null;
  cantidad: string;
  unidad: string | null;
}

export interface Transferencia {
  id: string;
  folio: string;
  estado: 'borrador' | 'despachada' | 'recibida' | 'anulada';
  en_camino: boolean;
  origen: { id: string; nombre: string | null; codigo: string | null };
  destino: { id: string; nombre: string | null; codigo: string | null };
  fecha_despacho_at: string | null;
  fecha_recepcion_at: string | null;
  despacho_por: string | null;
  recepcion_por: string | null;
  observaciones: string | null;
  items: TransferenciaItem[];
}

export interface VentaItem {
  variante_id: string;
  sku: string | null;
  variante: string | null;
  cantidad: string;
  unidad: string | null;
  precio_unitario: string;
  subtotal: string;
  descuento: string;
}

export interface Venta {
  id: string;
  folio: string;
  estado: 'registrada' | 'anulada';
  sucursal: string | null;
  caja_id: string | null;
  lista_precios: string | null;
  cliente_nombre: string | null;
  cliente_rut: string | null;
  subtotal: string;
  descuento: string;
  total: string;
  vendida_at: string;
  vendida_por: string | null;
}

export interface Caja {
  id: string;
  folio: string;
  estado: 'abierta' | 'cerrada' | 'anulada';
  fecha_apertura_at: string | null;
  monto_inicial: string;
  monto_declarado: string | null;
  monto_sistema: string | null;
  /** Diferencia con signo. Se guarda y se muestra completa. */
  diferencia: string | null;
  revision: 'dentro_de_tolerancia' | 'requiere_revision' | null;
  abrio: string | null;
  cerro: string | null;
  monto_esperado?: string;
  movimientos_por_tipo?: Record<string, string>;
}

export interface CajaMovimiento {
  id: string;
  tipo: string;
  monto: string;
  glosa: string | null;
  usuario: string | null;
  ocurrido_en: string;
}

export interface AlertaStock {
  id: string;
  variante_id: string;
  sucursal_id: string;
  stock_detectado: string;
  stock_minimo: string;
  periodo_clave: string;
  estado: 'pendiente' | 'enviada' | 'error';
}

/** Envoltura de paginacion que devuelve Laravel. */
export interface Paginado<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number | null;
  to: number | null;
}

/** Error de dominio de la ferreteria: 422 con código estable. */
export interface ErrorDeDominio {
  mensaje: string;
  codigo: string;
}

/** Un renglón del mostrador tal como lo manda la pantalla. */
export interface RenglonVenta {
  variante_id: string;
  cantidad: string;
  descuento?: string;
  detalle?: string;
}

/** Cotizacion que devuelve el mostrador antes de cobrar. */
export interface Cotizacion {
  renglones: Array<{
    variante_id: string;
    sku: string;
    nombre: string;
    cantidad: string;
    unidad: string;
    precio_unitario: string;
    precio_desde: 'lista' | 'mostrador';
    subtotal: string;
  }>;
  subtotal: string;
  descuento: string;
  total: string;
  lista_id: string | null;
  lista_nombre: string | null;
}

/** Resultado del arqueo de caja, con la diferencia completa. */
export interface ArqueoCaja {
  monto_declarado: string;
  monto_sistema: string;
  diferencia: string;
  diferencia_sobrante: boolean;
  diferencia_faltante: boolean;
  tolerancia: string;
  revision: 'dentro_de_tolerancia' | 'requiere_revision';
}